<?php

namespace App\Actions\Owner;

use App\Enums\ContractStatus;
use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\OwnerCaseStage;
use App\Exceptions\ApiException;
use App\Integrations\Rega\RegaGateway;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\OwnerCase;
use App\Notifications\Notices;
use App\Support\PublishingGate;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The owner's path, one property at a time (owner_cases.dart):
 *   contract (filed with REGA, approved by the owner through Nafath) → fee (the advertising licence fee, paid in
 *   the app) → licence (issued by REGA) → listing (the owner completes his listing) → active → closed (sold).
 * sync() is called on every look at the case and every 10 minutes (SyncOwnerContracts).
 */
final class OwnerCaseFlow
{
    public function __construct(private readonly RegaGateway $rega) {}

    public function open(Customer $owner, string $deedNumber): OwnerCase
    {
        PublishingGate::assert($owner, CustomerRole::Owner);
        // one case per deed on its way; a contract REGA refused (or let expire) is filed again
        if ($owner->ownerCases()->where('deed_number', $deedNumber)->whereNotIn('stage', [OwnerCaseStage::Closed])
            ->whereNotIn('contract_status', [ContractStatus::Rejected, ContractStatus::Expired])->exists()) {
            throw ApiException::of(ErrorCode::AlreadyActive);
        }
        $reference = $this->rega->submitBrokerageContract($deedNumber, $owner->phone);

        return OwnerCase::query()->create([
            'customer_id' => $owner->id,
            'deed_number' => $deedNumber,
            'stage' => OwnerCaseStage::Contract,
            'contract_status' => ContractStatus::Submitted,
            'contract_ref' => $reference,
            'commission_percent' => Settings::string(Setting::OwnerCommissionPercent),
            'last_checked_at' => now(),
        ]);
    }

    /** Asks REGA where the contract stands; moves the case on when it was approved. */
    public function sync(OwnerCase $case): OwnerCase
    {
        if ($case->stage !== OwnerCaseStage::Contract || $case->contract_status !== ContractStatus::Submitted || $case->contract_ref === null) {
            return $case;
        }
        $status = $this->rega->contractStatus($case->contract_ref);
        $case->forceFill(['last_checked_at' => now()]);
        if ($status === ContractStatus::Approved) {
            $case->forceFill(['contract_status' => $status, 'contract_approved_at' => now(), 'stage' => OwnerCaseStage::Fee])->save();
            $case->customer->notify(Notices::ownerCase($case, 'وافقت على عقد الوساطة', 'ادفع رسوم الترخيص الإعلاني لإصدار الترخيص.'));
        } elseif ($status !== ContractStatus::Submitted) {
            $case->forceFill(['contract_status' => $status])->save();
            $case->customer->notify(Notices::ownerCase($case, 'لم يُعتمد عقد الوساطة', "الصك {$case->deed_number}"));
        } else {
            $case->save();
        }

        return $case;
    }

    /**
     * The fee is paid (ConfirmPayment): REGA issues the licence and the owner can complete his listing. Only once:
     * a second payment for the same case (a late webhook, a second checkout) is refused, so ConfirmPayment marks
     * it «unapplied» for a refund instead of a second licence.
     */
    public function feePaid(OwnerCase $case, int $paymentId): void
    {
        DB::transaction(function () use ($case, $paymentId) {
            $case = OwnerCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($case->stage !== OwnerCaseStage::Fee || $case->fee_payment_id !== null) {
                throw ApiException::of(ErrorCode::InvalidTransition, ['stage' => $case->stage->value]);
            }
            $case->forceFill(['fee_payment_id' => $paymentId, 'stage' => OwnerCaseStage::Licence])->save();
            $issued = $this->rega->issueAdLicence((string) $case->contract_ref, $case->deed_number);
            $licence = AdLicense::query()->create([
                'customer_id' => $case->customer_id,
                'licence_number' => (string) $issued->number,
                'qr_url' => $issued->qrUrl,
                'source' => LicenceSource::Issued,
                'status' => LicenceStatus::Valid,
                'issued_at' => now(),
                'verified_at' => now(),
                'expires_at' => $issued->expiry(),
            ]);
            $case->forceFill(['ad_license_id' => $licence->id, 'stage' => OwnerCaseStage::Listing])->save();
            $case->customer->notify(Notices::ownerCase($case, 'صدر الترخيص الإعلاني', "أكمل تفاصيل عرضك للنشر — الترخيص {$licence->licence_number}"));
        });
    }
}
