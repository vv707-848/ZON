<?php

namespace App\Integrations\Stores;

use App\Integrations\Google\ServiceAccount;
use App\Integrations\IntegrationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Google Play Developer API (androidpublisher v3), signed in with a service account that Play Console lets
 * see orders and manage purchases.
 *
 * - **Subscription:** `purchases.subscriptionsv2.get` — the line item of the product, its expiry, and the latest
 *   successful order (one charge); `purchases.subscriptions.acknowledge` once delivered.
 * - **One-time product (a boost):** `purchases.productsv2.getproductpurchasev2`; `purchases.products.consume` once
 *   delivered, so it can be bought again. Unacknowledged purchases are refunded by Google after three days.
 * - **What was paid:** `orders.get` on the order — its total and tax.
 * - **Notices:** Real-time developer notifications through Pub/Sub push; only the purchase token is read, then the
 *   purchase is read back.
 */
final class GooglePlayStore implements StoreGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/';

    public function __construct(private readonly ServiceAccount $account, private readonly string $package) {}

    public function name(): string
    {
        return 'google';
    }

    public function transaction(string $productId, string $reference, bool $subscription): ?StoreTransaction
    {
        $base = $this->base().'purchases/';
        $response = $this->call('get', $subscription ? "{$base}subscriptionsv2/tokens/".rawurlencode($reference) : "{$base}productsv2/tokens/".rawurlencode($reference));
        if (in_array($response->status(), [400, 404, 410], true)) {
            return null;                                               // an unknown or expired token
        }
        if (! $response->successful()) {
            throw new IntegrationException('google_play', "HTTP {$response->status()}: ".(string) $response->json('error.status'));
        }
        $purchase = (array) $response->json();

        return $subscription ? $this->subscription($productId, $reference, $purchase) : $this->product($productId, $reference, $purchase);
    }

    public function settle(string $productId, string $reference, bool $subscription): void
    {
        $url = $subscription
            ? $this->base().'purchases/subscriptions/'.rawurlencode($productId).'/tokens/'.rawurlencode($reference).':acknowledge'
            : $this->base().'purchases/products/'.rawurlencode($productId).'/tokens/'.rawurlencode($reference).':consume';
        $response = $this->call('post', $url);
        if (! $response->successful()) {
            throw new IntegrationException('google_play', "HTTP {$response->status()}: ".(string) $response->json('error.status'));
        }
    }

    /** Pub/Sub push: {message: {data: base64(DeveloperNotification)}}. */
    public function notice(Request $request): ?StoreNotice
    {
        $json = base64_decode((string) $request->input('message.data'), true);
        $note = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($note) || ($note['packageName'] ?? null) !== $this->package) {
            return null;
        }
        foreach (['subscriptionNotification', 'oneTimeProductNotification', 'voidedPurchaseNotification'] as $kind) {
            $token = data_get($note, "{$kind}.purchaseToken");
            if (is_string($token) && $token !== '') {
                $product = data_get($note, "{$kind}.sku");

                return new StoreNotice($token, $token, is_string($product) ? $product : null,
                    $kind === 'voidedPurchaseNotification' ? (string) data_get($note, "{$kind}.orderId") : null);
            }
        }

        return null;                                                   // testNotification
    }

    /** @param array<string, mixed> $purchase SubscriptionPurchaseV2 */
    private function subscription(string $productId, string $token, array $purchase): ?StoreTransaction
    {
        $items = (array) ($purchase['lineItems'] ?? []);
        $item = collect($items)->firstWhere('productId', $productId) ?? ($items[0] ?? null);
        if (! is_array($item)) {
            return null;
        }
        $state = (string) ($purchase['subscriptionState'] ?? '');
        $pending = in_array($state, ['SUBSCRIPTION_STATE_PENDING', 'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED'], true);
        $order = $item['latestSuccessfulOrderId'] ?? $purchase['latestOrderId'] ?? null;
        [$amount, $currency, $tax] = $pending || ! is_string($order) ? [null, null, null] : $this->paid($order);

        return new StoreTransaction(
            store: 'google',
            reference: is_string($order) ? $order : "pending:{$token}",
            chain: $token,
            productId: (string) ($item['productId'] ?? ''),
            purchasedAt: isset($purchase['startTime']) ? CarbonImmutable::parse((string) $purchase['startTime']) : CarbonImmutable::now(),
            expiresAt: isset($item['expiryTime']) ? CarbonImmutable::parse((string) $item['expiryTime']) : null,
            revokedAt: null,                                           // a refund arrives as a voided-purchase notice
            test: isset($purchase['testPurchase']),
            accountToken: is_string($account = data_get($purchase, 'externalAccountIdentifiers.obfuscatedExternalAccountId')) ? $account : null,
            amount: $amount,
            currency: $currency,
            tax: $tax,
            pending: $pending,
            settled: ($purchase['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
        );
    }

    /** @param array<string, mixed> $purchase ProductPurchaseV2 */
    private function product(string $productId, string $token, array $purchase): ?StoreTransaction
    {
        $items = (array) ($purchase['productLineItem'] ?? []);
        $item = collect($items)->firstWhere('productId', $productId) ?? ($items[0] ?? null);
        if (! is_array($item)) {
            return null;
        }
        $state = (string) data_get($purchase, 'purchaseStateContext.purchaseState', '');
        $order = $purchase['orderId'] ?? null;
        $pending = $state !== 'PURCHASED' && $state !== 'CANCELLED';
        [$amount, $currency, $tax] = $state === 'PURCHASED' && is_string($order) ? $this->paid($order) : [null, null, null];

        return new StoreTransaction(
            store: 'google',
            reference: is_string($order) ? $order : "pending:{$token}",
            chain: $token,
            productId: (string) ($item['productId'] ?? ''),
            purchasedAt: isset($purchase['purchaseCompletionTime']) ? CarbonImmutable::parse((string) $purchase['purchaseCompletionTime']) : CarbonImmutable::now(),
            expiresAt: null,
            revokedAt: $state === 'CANCELLED' ? CarbonImmutable::now() : null,
            test: isset($purchase['testPurchaseContext']),
            accountToken: isset($purchase['obfuscatedExternalAccountId']) ? (string) $purchase['obfuscatedExternalAccountId'] : null,
            amount: $amount,
            currency: $currency,
            tax: $tax,
            pending: $pending,
            settled: data_get($item, 'productOfferDetails.consumptionState') === 'CONSUMPTION_STATE_CONSUMED',
        );
    }

    /**
     * What the customer paid for the order and the tax in it, in minor units (`orders.get`).
     *
     * @return array{0: int, 1: string, 2: int}
     */
    private function paid(string $orderId): array
    {
        $response = $this->call('get', $this->base().'orders/'.rawurlencode($orderId));
        $total = $response->json('total');
        if (! $response->successful() || ! is_array($total) || ! isset($total['currencyCode'])) {
            throw new IntegrationException('google_play', "order {$orderId}: HTTP {$response->status()}");
        }

        return [self::minor($total), (string) $total['currencyCode'], self::minor((array) $response->json('tax', []))];
    }

    /**
     * Money {units, nanos} → minor units of a two-decimal currency (29.99 → 2999).
     *
     * @param  array<string, mixed>  $money
     */
    private static function minor(array $money): int
    {
        return (int) ($money['units'] ?? 0) * 100 + intdiv((int) ($money['nanos'] ?? 0), 10_000_000);
    }

    private function base(): string
    {
        return self::API.rawurlencode($this->package).'/';
    }

    private function call(string $method, string $url): Response
    {
        try {
            $request = Http::withToken($this->account->accessToken(self::SCOPE))->acceptJson()->timeout(10);

            return $method === 'post' ? $request->post($url, []) : $request->get($url);
        } catch (ConnectionException $e) {
            throw new IntegrationException('google_play', 'connection failed', $e);
        }
    }
}
