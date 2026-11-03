<?php

namespace App\Integrations\Stores;

use App\Integrations\IntegrationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The App Store Server API (developer.apple.com/documentation/appstoreserverapi).
 *
 * - **Sign-in:** a JWT signed ES256 with the in-app purchase key of App Store Connect (iss = issuer id, kid = key
 *   id, aud = appstoreconnect-v1, bid = the bundle id), made for each request and valid five minutes (Apple allows
 *   sixty).
 * - **Get Transaction Info** `GET /inApps/v1/transactions/{transactionId}` on the production host first, then — on
 *   4040010 TransactionIdNotFoundError — on the sandbox host (Apple's own instruction when the environment is not
 *   known).
 * - The signed transaction comes back from Apple's server over TLS, so its payload is read as Apple's word; a notice
 *   is only a hint and is read back the same way.
 * - Apple sells as our commissionaire in Saudi Arabia and remits the tax: the price it reports is what the
 *   customer paid, and no invoice of ours goes with it.
 */
final class AppleAppStore implements StoreGateway
{
    private const NOT_FOUND = 4040010;

    private const INVALID_ID = 4000006;

    public function __construct(
        private readonly string $issuerId,
        private readonly string $keyId,
        private readonly string $privateKeyPath,
        private readonly string $bundleId,
        private readonly string $url,
        private readonly string $sandboxUrl,
    ) {}

    public function name(): string
    {
        return 'apple';
    }

    public function transaction(string $productId, string $reference, bool $subscription): ?StoreTransaction
    {
        if (preg_match('/^\d{1,40}$/', $reference) !== 1) {
            return null;                                               // Apple's transaction ids are digits
        }
        foreach ([$this->url, $this->sandboxUrl] as $host) {
            $response = $this->get(rtrim($host, '/').'/inApps/v1/transactions/'.$reference);
            $code = (int) $response->json('errorCode');
            if ($code === self::NOT_FOUND) {
                continue;                                              // try the other environment
            }
            if ($code === self::INVALID_ID) {
                return null;
            }
            if (! $response->successful() || ! is_string($signed = $response->json('signedTransactionInfo'))) {
                throw new IntegrationException('app_store', "HTTP {$response->status()}: ".(string) $response->json('errorMessage'));
            }

            return $this->map(self::payload($signed));
        }

        return null;
    }

    public function settle(string $productId, string $reference, bool $subscription): void
    {
        // The app finishes the transaction (StoreKit's Transaction.finish) once our server answered.
    }

    /** App Store Server Notifications V2: {signedPayload}. The transaction it names is read back from Apple. */
    public function notice(Request $request): ?StoreNotice
    {
        $notice = self::payload((string) $request->input('signedPayload'));
        $transaction = self::payload((string) data_get($notice, 'data.signedTransactionInfo'));
        $id = $transaction['transactionId'] ?? null;
        $original = $transaction['originalTransactionId'] ?? null;
        if (! is_string($id) || ! is_string($original) || ($transaction['bundleId'] ?? null) !== $this->bundleId) {
            return null;                                               // a test notice, or not ours
        }

        return new StoreNotice($original, $id, is_string($transaction['productId'] ?? null) ? $transaction['productId'] : null);
    }

    /** @param array<string, mixed> $tx */
    private function map(array $tx): ?StoreTransaction
    {
        if (($tx['bundleId'] ?? null) !== $this->bundleId || ! isset($tx['transactionId'], $tx['originalTransactionId'], $tx['productId'], $tx['purchaseDate'])) {
            return null;
        }
        $at = fn (string $field) => isset($tx[$field]) ? CarbonImmutable::createFromTimestampMs((int) $tx[$field]) : null;
        // price is in milliunits of the currency (29990 = 29.99): a tenth is the minor unit of a two-decimal currency.
        $price = isset($tx['price'], $tx['currency']) ? intdiv((int) $tx['price'], 10) : null;

        return new StoreTransaction(
            store: 'apple',
            reference: (string) $tx['transactionId'],
            chain: (string) $tx['originalTransactionId'],
            productId: (string) $tx['productId'],
            purchasedAt: CarbonImmutable::createFromTimestampMs((int) $tx['purchaseDate']),
            expiresAt: $at('expiresDate'),
            revokedAt: $at('revocationDate'),
            test: ($tx['environment'] ?? 'Production') !== 'Production',
            accountToken: isset($tx['appAccountToken']) ? strtolower((string) $tx['appAccountToken']) : null,
            amount: $price,
            currency: isset($tx['currency']) ? (string) $tx['currency'] : null,
            settled: true,                                             // the app finishes it
        );
    }

    private function get(string $url): Response
    {
        try {
            return Http::withToken($this->jwt())->acceptJson()->timeout(10)->get($url);
        } catch (ConnectionException $e) {
            throw new IntegrationException('app_store', 'connection failed', $e);
        }
    }

    /** ES256 over the header and claims; openssl gives a DER signature, JWS wants r‖s (32 bytes each). */
    private function jwt(): string
    {
        $key = is_file($this->privateKeyPath) ? openssl_pkey_get_private((string) file_get_contents($this->privateKeyPath)) : false;
        if ($key === false) {
            throw new IntegrationException('app_store', 'unreadable in-app purchase key');
        }
        $now = time();
        $input = self::encode(['alg' => 'ES256', 'kid' => $this->keyId, 'typ' => 'JWT']).'.'
            .self::encode(['iss' => $this->issuerId, 'iat' => $now, 'exp' => $now + 300, 'aud' => 'appstoreconnect-v1', 'bid' => $this->bundleId]);
        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new IntegrationException('app_store', 'cannot sign with the in-app purchase key');
        }

        return $input.'.'.self::base64url(self::rawSignature($der));
    }

    /** DER SEQUENCE { INTEGER r, INTEGER s } → r‖s, each left-padded to 32 bytes. */
    public static function rawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);        // SEQUENCE tag + length
        $parts = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $length), "\x00");
            $parts .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts;
    }

    /** @return array<string, mixed> the JSON payload of a compact JWS (its middle part) */
    public static function payload(string $jws): array
    {
        $parts = explode('.', $jws);
        $json = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $part */
    private static function encode(array $part): string
    {
        return self::base64url((string) json_encode($part));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
