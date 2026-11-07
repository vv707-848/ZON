<?php

namespace App\Integrations\Stores;

use App\Actions\Billing\ApplyBoost;
use App\Enums\BoostKind;
use App\Enums\PaymentPurpose;
use App\Models\Package;
use App\Support\StoreProducts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * SIMULATION — no store, no money. It replaces a store until its keys arrive; the app's simulated sheet (and the
 * tests) send `sim:{account token}:{id}`, with flags appended: `:pending`, `:revoked`, `:test`, `:renewal-N` (the
 * Nth renewal: a new charge of the same purchase), `:grace-N` (the charge's expiry moved N days later, as Google
 * does while it retries a failed renewal). A subscription charge runs its plan's length from the moment the store
 * first saw it — fixed, as a real store's is; the amount is the plan's or the boost's listed price. Its notices are
 * `{"simulation": {chain, reference, product_id?, voided?}}`.
 *
 * In production — only if PRODUCTION_ALLOWS_FAKE names the store — every simulated purchase is a test one, so it
 * reaches review and test accounts only.
 */
final class FakeStore implements StoreGateway
{
    public function __construct(private readonly string $store) {}

    public function transaction(string $productId, string $reference, bool $subscription): ?StoreTransaction
    {
        $product = StoreProducts::find($productId);
        if ($product === null || preg_match('/^sim:([0-9a-f-]{36}):([A-Za-z0-9]{6,40})((?::[a-z0-9-]+)*)$/', $reference, $m) !== 1) {
            return null;
        }
        $flags = array_values(array_filter(explode(':', $m[3])));
        $renewal = $grace = 0;
        foreach ($flags as $flag) {
            if (preg_match('/^(renewal|grace)-(\d+)$/', $flag, $r) === 1) {
                $r[1] === 'renewal' ? $renewal = (int) $r[2] : $grace = (int) $r[2];
            }
        }
        $now = CarbonImmutable::now();
        $charge = "sim-{$m[2]}".($renewal > 0 ? "-r{$renewal}" : '');
        $seen = CarbonImmutable::parse((string) Cache::rememberForever("fake-store:{$this->store}:{$charge}", fn () => $now->toIso8601String()));
        $package = $product['package'] === null ? null : Package::query()->where('code', $product['package'])->first();
        $price = $product['purpose'] === PaymentPurpose::Subscription
            ? $package?->price()->halalas
            : ApplyBoost::price(BoostKind::from($product['purpose']->value))->halalas;

        return new StoreTransaction(
            store: $this->store,
            reference: $charge,
            chain: "sim:{$m[1]}:{$m[2]}",
            productId: $productId,
            expiresAt: $subscription && $package !== null ? $seen->addDays((int) $package->days() + $grace) : null,
            revokedAt: in_array('revoked', $flags, true) ? $now : null,
            test: in_array('test', $flags, true) || app()->isProduction(),
            accountToken: $m[1],
            amount: $price,
            currency: 'SAR',
            pending: in_array('pending', $flags, true),
            settled: true,
        );
    }

    public function settle(string $productId, string $reference, bool $subscription): void {}

    public function notice(Request $request): ?StoreNotice
    {
        $sim = $request->input('simulation');
        if (! is_array($sim) || ! is_string($sim['chain'] ?? null) || ! is_string($sim['reference'] ?? null)) {
            return null;
        }

        return new StoreNotice($sim['chain'], $sim['reference'], isset($sim['product_id']) ? (string) $sim['product_id'] : null,
            isset($sim['voided']) ? (string) $sim['voided'] : null);
    }
}
