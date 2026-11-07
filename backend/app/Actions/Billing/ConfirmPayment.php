<?php

namespace App\Actions\Billing;

use App\Actions\Owner\OwnerCaseFlow;
use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Exceptions\ApiException;
use App\Integrations\Payments\GatewayPayment;
use App\Integrations\Payments\PaymentGateway;
use App\Jobs\IssueInvoice;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\Notices;
use App\Services\Billing\Entitlements;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A payment is «paid» only after the provider itself says so: the reference is read back from the provider
 * (fetch), and amount, currency and our payment id must all match — otherwise «failed». A failed payment can
 * still become paid: the provider's sheet lets the customer retry, and the retry is a new reference for the
 * same payment. A paid payment whose target can no longer take it (sold, deleted, already paid for) is
 * «unapplied» (staff refund it from the panel). Confirming twice changes nothing.
 */
final class ConfirmPayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ApplyBoost $boost,
        private readonly OwnerCaseFlow $ownerCases,
    ) {}

    public function __invoke(Payment $payment, string $reference): Payment
    {
        if (! self::open($payment)) {
            return $payment;
        }
        $remote = $this->gateway->fetch($reference);

        return DB::transaction(function () use ($payment, $reference, $remote) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! self::open($payment)) {
                return $payment;
            }
            if ($remote->status === GatewayPayment::PENDING) {
                return $payment;
            }

            $problem = match (true) {
                $remote->status !== GatewayPayment::PAID => $remote->failureReason ?? 'declined',
                $remote->amountHalalas !== $payment->amount_halalas => 'amount_mismatch',
                $remote->currency !== $payment->currency => 'currency_mismatch',
                $remote->paymentPublicId !== $payment->public_id => 'payment_mismatch',
                default => null,
            };
            if ($problem !== null && $payment->status === PaymentStatus::Failed) {
                return $payment;                                      // still nothing paid: the first failure stays on record
            }
            try {
                $payment->forceFill(['provider_ref' => $reference])->save();
            } catch (UniqueConstraintViolationException) {
                $problem = 'reference_reused';                       // a reference already used by another payment
                $payment->forceFill(['provider_ref' => null]);
            }
            if ($problem !== null) {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => $problem])->save();
                Log::warning('payment.failed', ['payment' => $payment->public_id, 'reason' => $problem]);

                return $payment;
            }

            $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
            $applied = $this->apply($payment);
            if (! $applied) {
                $payment->forceFill(['status' => PaymentStatus::Unapplied])->save();
            }
            IssueInvoice::dispatch($payment->id)->afterCommit();
            activity('payments')->performedOn($payment)->causedBy($payment->customer)->event($applied ? 'paid' : 'unapplied')
                ->withProperties(['amount' => $payment->amount_halalas, 'purpose' => $payment->purpose->value])->log('دفعة');

            return $payment;
        });
    }

    /** Still waiting for money: pending, or failed (a retry in the provider's sheet may still pay it). */
    private static function open(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending || $payment->status === PaymentStatus::Failed;
    }

    private function apply(Payment $payment): bool
    {
        $customer = $payment->customer;
        $target = $payment->target;
        Entitlements::forget($customer);
        if ($target === null) {
            return false;                                             // deleted meanwhile (a listing, a request): refund
        }

        try {
            match ($payment->purpose) {
                PaymentPurpose::Subscription => $this->subscribe($payment),
                PaymentPurpose::Featured, PaymentPurpose::UrgentListing, PaymentPurpose::UrgentRequest => ($this->boost)($customer, $target, BoostKind::from($payment->purpose->value), $payment),
                PaymentPurpose::AdLicence => $target instanceof OwnerCase ? $this->ownerCases->feePaid($target, $payment->id) : throw ApiException::of(ErrorCode::InvalidTransition),
            };
        } catch (ApiException) {
            return false;                                             // e.g. the listing was sold meanwhile
        }

        return true;
    }

    /** A new period; the same plan bought again continues from the end of its last period, even one bought ahead. */
    private function subscribe(Payment $payment): void
    {
        $package = $payment->target;
        assert($package instanceof Package);
        $starts = Subscription::nextStart($payment->customer_id, $package->id);

        Subscription::query()->create([
            'customer_id' => $payment->customer_id,
            'package_id' => $package->id,
            'source' => SubscriptionSource::Purchase,
            'payment_id' => $payment->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addDays((int) $package->days()),
        ]);
        Entitlements::forget($payment->customer);
        $payment->customer->notify(Notices::planActivated($package));
    }
}
