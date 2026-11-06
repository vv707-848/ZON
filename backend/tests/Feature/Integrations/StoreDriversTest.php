<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Google\ServiceAccount;
use App\Integrations\IntegrationException;
use App\Integrations\Stores\AppleAppStore;
use App\Integrations\Stores\FakeStore;
use App\Integrations\Stores\GooglePlayStore;
use App\Integrations\Stores\Stores;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The store drivers against answers shaped like Apple's and Google's documentation: the App Store Server API (an
 * ES256 JWT, production then sandbox on 4040010) and the Play Developer API (subscriptionsv2, productsv2, orders,
 * acknowledge, consume). Going live still needs a purchase with the real accounts (docs/integrations.md).
 */
class StoreDriversTest extends TestCase
{
    private const TOKEN = '6f1c8a52-3c1e-4f43-9a7d-2a3a8b1f0c11';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(fn (string $f) => @unlink($f), $this->files);
        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'store');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $payload a JWS whose signature we never read (Apple's answer arrives over TLS) */
    private static function jws(array $payload): string
    {
        $part = fn (array $p) => rtrim(strtr(base64_encode((string) json_encode($p)), '+/', '-_'), '=');

        return $part(['alg' => 'ES256', 'x5c' => []]).'.'.$part($payload).'.c2lnbmF0dXJl';
    }

    private function apple(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        return [new AppleAppStore('57246542-96fe-1a63-e053-0824d011072a', '2X9R4HXF34', $this->file($pem), 'com.zon.app', 'https://api.storekit.apple.com', 'https://api.storekit-sandbox.apple.com'), $public];
    }

    public function test_apple_signs_es256_tries_production_then_sandbox_and_reads_the_transaction(): void
    {
        [$apple, $public] = $this->apple();
        $tx = ['transactionId' => '2000000123456789', 'originalTransactionId' => '2000000111111111', 'bundleId' => 'com.zon.app', 'productId' => 'com.zon.app.seeker.month',
            'purchaseDate' => 1_793_700_000_000, 'expiresDate' => 1_796_292_000_000, 'type' => 'Auto-Renewable Subscription', 'appAccountToken' => strtoupper(self::TOKEN),
            'environment' => 'Sandbox', 'price' => 199_000, 'currency' => 'SAR', 'transactionReason' => 'PURCHASE'];
        Http::fake([
            'api.storekit.apple.com/*' => Http::response(['errorCode' => 4040010, 'errorMessage' => 'Transaction id not found.'], 404),
            'api.storekit-sandbox.apple.com/*' => Http::response(['signedTransactionInfo' => self::jws($tx)]),
        ]);

        $read = $apple->transaction('com.zon.app.seeker.month', '2000000123456789', true);

        $this->assertNotNull($read);
        $this->assertSame(['2000000123456789', '2000000111111111', true, self::TOKEN, 19_900, 'SAR'], [$read->reference, $read->chain, $read->test, $read->accountToken, $read->amount, $read->currency]);
        $this->assertSame(1_796_292_000, $read->expiresAt?->getTimestamp());
        Http::assertSentInOrder([
            fn (SentRequest $r) => $r->url() === 'https://api.storekit.apple.com/inApps/v1/transactions/2000000123456789',
            fn (SentRequest $r) => $r->url() === 'https://api.storekit-sandbox.apple.com/inApps/v1/transactions/2000000123456789',
        ]);

        // The JWT: Apple's header and claims, and an ES256 signature its public key accepts.
        $jwt = substr(Http::recorded()[0][0]->header('Authorization')[0], 7);
        [$h, $p, $s] = explode('.', $jwt);
        $decode = fn (string $part) => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);
        $this->assertSame(['alg' => 'ES256', 'kid' => '2X9R4HXF34', 'typ' => 'JWT'], $decode($h));
        $claims = $decode($p);
        $this->assertSame(['57246542-96fe-1a63-e053-0824d011072a', 'appstoreconnect-v1', 'com.zon.app'], [$claims['iss'], $claims['aud'], $claims['bid']]);
        $this->assertLessThanOrEqual(3600, $claims['exp'] - $claims['iat']);
        $raw = (string) base64_decode(strtr($s, '-_', '+/'));
        $int = fn (string $n) => "\x02".chr(strlen($n = ltrim($n, "\x00")) + (ord($n[0]) > 0x7F ? 1 : 0)).(ord($n[0]) > 0x7F ? "\x00" : '').$n;
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
        $this->assertSame(1, openssl_verify("{$h}.{$p}", "\x30".chr(strlen($body)).$body, $public, OPENSSL_ALGO_SHA256));
    }

    public function test_apple_knows_nothing_of_a_strange_id_and_reads_its_notices_back(): void
    {
        [$apple] = $this->apple();
        Http::fake(['*' => Http::response(['errorCode' => 4040010], 404)]);

        $this->assertNull($apple->transaction('com.zon.app.featured', 'not-digits', false));
        Http::assertNothingSent();
        $this->assertNull($apple->transaction('com.zon.app.featured', '2000000999', false), 'in neither environment');

        $signed = self::jws(['notificationType' => 'DID_RENEW', 'data' => ['bundleId' => 'com.zon.app', 'signedTransactionInfo' => self::jws(['transactionId' => '2000000222', 'originalTransactionId' => '2000000111', 'bundleId' => 'com.zon.app', 'productId' => 'com.zon.app.seeker.month'])]]);
        $notice = $apple->notice(Request::create('/', 'POST', ['signedPayload' => $signed]));
        $this->assertSame(['2000000111', '2000000222', 'com.zon.app.seeker.month'], [$notice?->chain, $notice?->reference, $notice?->productId]);
        $other = self::jws(['data' => ['signedTransactionInfo' => self::jws(['transactionId' => '1', 'originalTransactionId' => '1', 'bundleId' => 'com.other.app'])]]);
        $this->assertNull($apple->notice(Request::create('/', 'POST', ['signedPayload' => $other])), "another app's notice");
    }

    private function google(): GooglePlayStore
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $account = $this->file((string) json_encode(['type' => 'service_account', 'client_email' => 'play@zon.iam.gserviceaccount.com', 'private_key' => $pem]));

        return new GooglePlayStore(new ServiceAccount($account, 'google_play'), 'com.zon.app');
    }

    public function test_google_reads_a_subscription_and_its_order_then_acknowledges_it(): void
    {
        $api = 'androidpublisher.googleapis.com/androidpublisher/v3/applications/com.zon.app';
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.play', 'expires_in' => 3599]),
            "{$api}/purchases/subscriptionsv2/tokens/*" => Http::response([
                'kind' => 'androidpublisher#subscriptionPurchaseV2', 'regionCode' => 'SA', 'startTime' => '2026-11-03T09:00:00Z',
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING', 'testPurchase' => (object) [],
                'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => self::TOKEN],
                'lineItems' => [['productId' => 'com.zon.app.broker.month', 'expiryTime' => '2026-12-03T09:00:00Z', 'latestSuccessfulOrderId' => 'GPA.3341-2525-6644-12345',
                    'autoRenewingPlan' => ['autoRenewEnabled' => true, 'recurringPrice' => ['currencyCode' => 'SAR', 'units' => '280']]]],
            ]),
            "{$api}/orders/*" => Http::response(['orderId' => 'GPA.3341-2525-6644-12345', 'total' => ['currencyCode' => 'SAR', 'units' => '280'], 'tax' => ['currencyCode' => 'SAR', 'units' => '36', 'nanos' => 520_000_000]]),
            "{$api}/purchases/subscriptions/*" => Http::response([]),
        ]);
        $google = $this->google();

        $read = $google->transaction('com.zon.app.broker.month', 'token-abc', true);

        $this->assertSame(['GPA.3341-2525-6644-12345', 'token-abc', true, self::TOKEN, 28_000, 'SAR', 3_652, false, false],
            [$read?->reference, $read?->chain, $read?->test, $read?->accountToken, $read?->amount, $read?->currency, $read?->tax, $read?->settled, $read?->pending]);
        $this->assertSame('2026-12-03T09:00:00+00:00', $read?->expiresAt?->toIso8601String());

        $google->settle('com.zon.app.broker.month', 'token-abc', true);
        Http::assertSent(fn (SentRequest $r) => $r->method() === 'POST' && $r->url() === "https://{$api}/purchases/subscriptions/com.zon.app.broker.month/tokens/token-abc:acknowledge"
            && $r->hasHeader('Authorization', 'Bearer ya29.play'));
        Http::assertSent(fn (SentRequest $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
    }

    public function test_google_reads_a_one_time_purchase_consumes_it_and_reads_its_notices(): void
    {
        $api = 'androidpublisher.googleapis.com/androidpublisher/v3/applications/com.zon.app';
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.play', 'expires_in' => 3599]),
            "{$api}/purchases/productsv2/tokens/token-pending" => Http::response(['purchaseStateContext' => ['purchaseState' => 'PENDING'], 'productLineItem' => [['productId' => 'com.zon.app.featured']]]),
            "{$api}/purchases/productsv2/tokens/token-gone" => Http::response(['error' => ['status' => 'NOT_FOUND']], 404),
            "{$api}/purchases/productsv2/tokens/*" => Http::response([
                'purchaseStateContext' => ['purchaseState' => 'PURCHASED'], 'orderId' => 'GPA.1111-2222-3333-44444', 'purchaseCompletionTime' => '2026-11-03T10:00:00Z',
                'obfuscatedExternalAccountId' => self::TOKEN, 'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
                'productLineItem' => [['productId' => 'com.zon.app.featured', 'productOfferDetails' => ['quantity' => 1, 'consumptionState' => 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED']]],
            ]),
            "{$api}/orders/*" => Http::response(['total' => ['currencyCode' => 'SAR', 'units' => '100', 'nanos' => 0], 'tax' => ['currencyCode' => 'SAR', 'units' => '13', 'nanos' => 40_000_000]]),
            "{$api}/purchases/products/*" => Http::response([]),
        ]);
        $google = $this->google();

        $read = $google->transaction('com.zon.app.featured', 'token-1', false);
        $this->assertSame(['GPA.1111-2222-3333-44444', 10_000, 1_304, false, false], [$read?->reference, $read?->amount, $read?->tax, $read?->test, $read?->settled]);
        $this->assertTrue($google->transaction('com.zon.app.featured', 'token-pending', false)?->pending);
        $this->assertNull($google->transaction('com.zon.app.featured', 'token-gone', false));

        $google->settle('com.zon.app.featured', 'token-1', false);
        Http::assertSent(fn (SentRequest $r) => $r->url() === "https://{$api}/purchases/products/com.zon.app.featured/tokens/token-1:consume");

        $push = fn (array $note) => Request::create('/', 'POST', ['message' => ['data' => base64_encode((string) json_encode(['version' => '1.0', 'packageName' => 'com.zon.app'] + $note)), 'messageId' => '1']]);
        $voided = $google->notice($push(['voidedPurchaseNotification' => ['purchaseToken' => 'token-1', 'orderId' => 'GPA.1111-2222-3333-44444', 'productType' => 2]]));
        $this->assertSame(['token-1', 'GPA.1111-2222-3333-44444'], [$voided?->chain, $voided?->voidedReference]);
        $this->assertSame('token-9', $google->notice($push(['subscriptionNotification' => ['version' => '1.0', 'notificationType' => 2, 'purchaseToken' => 'token-9']]))?->reference);
        $this->assertNull($google->notice($push(['testNotification' => ['version' => '1.0']])));
    }

    public function test_an_order_google_cannot_show_stops_the_purchase_rather_than_record_a_zero(): void
    {
        $api = 'androidpublisher.googleapis.com/androidpublisher/v3/applications/com.zon.app';
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.play', 'expires_in' => 3599]),
            "{$api}/purchases/productsv2/tokens/*" => Http::response(['purchaseStateContext' => ['purchaseState' => 'PURCHASED'], 'orderId' => 'GPA.9', 'productLineItem' => [['productId' => 'com.zon.app.featured']]]),
            "{$api}/orders/*" => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->expectException(IntegrationException::class);
        $this->google()->transaction('com.zon.app.featured', 'token-1', false);
    }

    public function test_each_store_switches_alone_and_production_refuses_their_simulation(): void
    {
        $this->assertInstanceOf(FakeStore::class, Stores::for('apple'));
        $purchase = Stores::for('google')->transaction('com.zon.app.featured', 'sim:'.Str::uuid().':abc123', false);
        $this->assertSame('google', $purchase?->store, 'each store has its own simulation');

        $this->app['env'] = 'production';
        config(['services.production_allows_fake' => 'google_play']);
        $this->app->forgetInstance(Stores::APPLE);
        $this->app->forgetInstance(Stores::GOOGLE);
        $this->assertInstanceOf(FakeStore::class, Stores::for('google'), 'listed: allowed');
        $this->expectExceptionMessage('The simulated app_store driver is not allowed in production');
        Stores::for('apple');
    }
}
