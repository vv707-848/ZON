<?php

namespace App\Actions\Billing;

use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Exceptions\ApiException;
use App\Integrations\IntegrationException;
use App\Integrations\Stores\StoreNotice;
use App\Integrations\Stores\Stores;
use App\Integrations\Stores\StoreTransaction;
use App\Jobs\IssueInvoice;
use App\Jobs\SettleStorePurchase;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\Notices;
use App\Services\Billing\Entitlements;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use App\Support\StoreProducts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A purchase in the apps (App Store · Google Play), applied once and only on the store's word.
 *
 * - **The claim:** after the store's sheet closed, the app sends what identifies the purchase; the store is asked,
 *   and the purchase applies when it is the product claimed, paid, not refunded, made from this account (the
 *   store token the app attached), for real money — a sandbox or test purchase counts for review and test accounts
 *   only (Sandbox) — and, for a subscription, not over.
 * - **Once:** one charge (Apple's transaction · Google's order) is one payment; sent again, it answers that payment.
 * - **What it buys:** a plan period up to the store's own expiry, or a boost on the listing or request the app
 *   named. A target that can no longer take it leaves the payment «unapplied»: the store refunds it.
 * - **The store is told** it was delivered (SettleStorePurchase): Google refunds what is not acknowledged in three
 *   days.
 * - **Notices** (renewal, refund) are read back from the store: a renewal of a purchase we know is a new period, a
 *   refund ends what its charge bought.
 * - **A charge's expiry moved later** by the store (Google's grace period, while a failed renewal is retried: the
 *   customer keeps the plan) moves its period's end with it — read from the store on a notice or a claim sent again,
 *   with no second payment, invoice or balance. Never earlier: an account hold's past expiry ends nothing sooner.
 * - **No invoice of ours:** Apple sells as our commissionaire and remits the tax; Google too, unless our VAT number is
 *   registered with Google (Setting::GooglePlayVatByZon) — then each Google purchase gets our invoice.
 */
final class ApplyStorePurchase
{
    public function __construct(private readonly PurchaseTarget $targets, private readonly ApplyBoost $boost, private readonly EndPurchase $end) {}

    /** @param ?array{type: string, id: int} $target the listing or request a boost is for */
    public function __invoke(Customer $customer, string $store, string $productId, string $reference, ?array $target): Payment
    {
        $product = StoreProducts::find($productId)
            ?? throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['product_id' => [__('validation.in', ['attribute' => 'product_id'])]]]);
        $subscription = $product['purpose'] === PaymentPurpose::Subscription;
        $tx = Stores::for($store)->transaction($productId, $reference, $subscription) ?? throw self::refused('unknown');

        $known = self::payment($store, $tx->reference);
        if ($known !== null) {
            if ($known->customer_id !== $customer->id) {
                throw self::refused('other_account');
            }
            $this->extend($known, $tx);

            return $known;
        }
        if (($problem = self::problem($customer, $tx, $productId)) !== null) {
            throw self::refused($problem);
        }

        return $this->record($customer, $tx, $product, $target);
    }

    /** A store's notice, read back from the store: a renewal of a purchase we know, or a refund. */
    public function notice(string $store, StoreNotice $notice): void
    {
        $first = Payment::query()->where('provider', $store)->where('store_chain', $notice->chain)->oldest('id')->first();
        if ($first === null) {
            return;                                                    // a purchase the app never sent: nothing of ours
        }
        if ($notice->voidedReference !== null) {
            $this->refunded(self::payment($store, $notice->voidedReference));

            return;
        }
        $productId = $notice->productId ?? (string) ($first->meta['product_id'] ?? '');
        $product = StoreProducts::find($productId);
        $tx = $product === null ? null : Stores::for($store)->transaction($productId, $notice->reference, $product['purpose'] === PaymentPurpose::Subscription);
        if ($product === null || $tx === null || $tx->chain !== $notice->chain) {
            return;
        }
        $known = self::payment($store, $tx->reference);
        if ($tx->revokedAt !== null) {
            $this->refunded($known);

            return;
        }
        if ($known !== null) {
            $this->extend($known, $tx);

            return;
        }
        if ($product['purpose'] === PaymentPurpose::Subscription && self::problem($first->customer, $tx, $productId) === null) {
            $this->record($first->customer, $tx, $product, null);    // a renewal: the next period
        }
    }

    /** @return ?string why the store's answer does not let it apply to this account */
    private static function problem(Customer $customer, StoreTransaction $tx, string $productId): ?string
    {
        if (! $tx->pending && ($tx->amount === null || $tx->currency === null)) {
            throw new IntegrationException($tx->store === 'apple' ? 'app_store' : 'google_play', 'the store reported no price');
        }

        return match (true) {
            $tx->productId !== $productId => 'product_mismatch',
            $tx->pending => 'pending',
            $tx->revokedAt !== null => 'refunded',
            $tx->accountToken === null || ! hash_equals($customer->storeToken(), strtolower($tx->accountToken)) => 'other_account',
            $tx->test && ! $customer->is_tester => 'test_purchase',
            $tx->expiresAt !== null && $tx->expiresAt->isPast() => 'expired',
            default => null,
        };
    }

    /**
     * One charge → one payment, then what it buys.
     *
     * @param  array{purpose: PaymentPurpose, package: ?string}  $product
     * @param  ?array{type: string, id: int}  $target
     */
    private function record(Customer $customer, StoreTransaction $tx, array $product, ?array $target): Payment
    {
        $invoiced = $tx->store === 'google' && Settings::bool(Setting::GooglePlayVatByZon);
        $subscription = $product['purpose'] === PaymentPurpose::Subscription;

        $payment = DB::transaction(function () use ($customer, $tx, $product, $target, $invoiced) {
            try {
                [$model] = $this->targets->resolve($customer, $product['purpose'], $product['package'], $target);
                $problem = null;
            } catch (ApiException $e) {
                [$model, $problem] = [null, strtolower($e->error->value)];  // paid, but it cannot take it: «unapplied»
            }
            try {
                $payment = Payment::query()->create([
                    'customer_id' => $customer->id,
                    'purpose' => $product['purpose'],
                    'target_type' => $model?->getMorphClass(),
                    'target_id' => $model?->getKey(),
                    'amount_halalas' => $tx->amount,
                    'vat_halalas' => $invoiced ? (int) $tx->tax : 0,
                    'currency' => $tx->currency,
                    'provider' => $tx->store,
                    'provider_ref' => $tx->reference,
                    'store_chain' => $tx->chain,
                    'status' => PaymentStatus::Paid,
                    'paid_at' => now(),
                    'meta' => ['product_id' => $tx->productId, 'test' => $tx->test, 'expires_at' => $tx->expiresAt?->toIso8601String()],
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;                                           // the same charge, recorded a moment ago
            }
            $applied = $model !== null && $this->apply($payment, $tx, $model);
            if (! $applied) {
                $payment->forceFill(['status' => PaymentStatus::Unapplied, 'failure_reason' => $problem ?? 'not_applicable'])->save();
            }
            activity('payments')->performedOn($payment)->causedBy($customer)->event($applied ? 'paid' : 'unapplied')
                ->withProperties(['amount' => $payment->amount_halalas, 'purpose' => $payment->purpose->value, 'store' => $tx->store])->log('شراء من المتجر');
            if ($invoiced) {
                IssueInvoice::dispatch($payment->id)->afterCommit();
            }

            return $payment;
        });
        $payment ??= self::payment($tx->store, $tx->reference) ?? throw self::refused('unknown');
        if (! $tx->settled) {
            SettleStorePurchase::dispatch($tx->store, $tx->productId, $tx->chain, $subscription);
        }

        return $payment;
    }

    private function apply(Payment $payment, StoreTransaction $tx, Model $model): bool
    {
        try {
            if ($model instanceof Package) {
                $this->period($payment, $tx, $model);
            } else {
                ($this->boost)($payment->customer, $model, BoostKind::from($payment->purpose->value), $payment);
            }
        } catch (ApiException) {
            return false;                                              // e.g. already featured meanwhile
        }

        return true;
    }

    /** A plan period up to the store's expiry; a renewal follows its purchase's previous period. */
    private function period(Payment $payment, StoreTransaction $tx, Package $package): void
    {
        $previous = Subscription::query()->whereIn('payment_id', Payment::query()->select('id')->where('provider', $tx->store)->where('store_chain', $tx->chain))->max('ends_at');
        $starts = $previous !== null && now()->lt($previous) ? Carbon::parse($previous) : now();
        $ends = $tx->expiresAt ?? throw new IntegrationException($tx->store === 'apple' ? 'app_store' : 'google_play', 'a subscription without its expiry');
        Subscription::query()->create([
            'customer_id' => $payment->customer_id,
            'package_id' => $package->id,
            'source' => SubscriptionSource::Purchase,
            'payment_id' => $payment->id,
            'starts_at' => $starts,
            'ends_at' => $ends->max($starts),
        ]);
        Entitlements::forget($payment->customer);
        if ($previous === null) {
            $payment->customer->notify(Notices::planActivated($package));
        }
    }

    /** The store moved this charge's expiry later: its period ends then too (only later, only for that very product). */
    private function extend(Payment $payment, StoreTransaction $tx): void
    {
        if ($payment->status !== PaymentStatus::Paid || $payment->purpose !== PaymentPurpose::Subscription || $tx->expiresAt === null
            || $tx->pending || $tx->revokedAt !== null || $tx->productId !== ($payment->meta['product_id'] ?? null)) {
            return;
        }
        $moved = Subscription::query()->where('payment_id', $payment->id)->whereNull('cancelled_at')
            ->where('ends_at', '<', $tx->expiresAt)->update(['ends_at' => $tx->expiresAt]);       // one statement: a stale read never moves it back
        if ($moved > 0) {
            $payment->forceFill(['meta' => [...(array) $payment->meta, 'expires_at' => $tx->expiresAt->toIso8601String()]])->save();
            Entitlements::forget($payment->customer);
            activity('payments')->performedOn($payment)->event('store_extended')
                ->withProperties(['store' => $payment->provider, 'until' => $tx->expiresAt->toIso8601String()])->log('مدّد المتجر الاشتراك');
        }
    }

    private function refunded(?Payment $payment): void
    {
        if ($payment === null || ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true)) {
            return;
        }
        DB::transaction(function () use ($payment) {
            $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
            ($this->end)($payment);
            activity('payments')->performedOn($payment)->event('store_refund')->withProperties(['store' => $payment->provider])->log('استرداد من المتجر');
        });
    }

    private static function payment(string $store, string $reference): ?Payment
    {
        return Payment::query()->where('provider', $store)->where('provider_ref', $reference)->first();
    }

    private static function refused(string $reason): ApiException
    {
        return ApiException::of(ErrorCode::PurchaseNotVerified, ['reason' => $reason]);
    }
}
