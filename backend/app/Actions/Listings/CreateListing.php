<?php

namespace App\Actions\Listings;

use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Enums\OwnerCaseStage;
use App\Enums\TextReview;
use App\Exceptions\ApiException;
use App\Integrations\Rega\RegaGateway;
use App\Jobs\CleanListingText;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingHistory;
use App\Models\OwnerCase;
use App\Services\Billing\Entitlements;
use App\Support\Actor;
use App\Support\MediaLibrary;
use App\Support\PublishingGate;
use Illuminate\Support\Facades\DB;

/**
 * «نشر عرض» (§10.2): the gates (role · active · FAL · Nafath · plan), the licence (a broker's licence verified
 * with REGA now; an owner's comes from his case), then the listing is saved as «قيد الصياغة» and the AI wording
 * is prepared on the `ai` queue (CleanListingText). Developers publish projects, not single listings.
 */
final class CreateListing
{
    public function __construct(private readonly RegaGateway $rega) {}

    /** @param array<string, mixed> $columns @param list<int> $mediaIds */
    public function __invoke(Customer $customer, array $columns, array $mediaIds, ?int $coverId, ?string $licenceNumber, ?int $ownerCaseId): Listing
    {
        PublishingGate::assert($customer, CustomerRole::Broker, CustomerRole::Owner);
        if (! Entitlements::for($customer)->canAddListing()) {
            throw ApiException::of(ErrorCode::PlanLimitReached);
        }

        $case = null;
        $check = null;
        if ($customer->role === CustomerRole::Owner) {
            $case = OwnerCase::query()->whereKey($ownerCaseId)->where('customer_id', $customer->id)->first();
            if ($case === null || $case->stage !== OwnerCaseStage::Listing || ! ($case->licence?->isValid() ?? false)) {
                throw ApiException::of(ErrorCode::LicenceRequired);
            }
        } else {
            if ($licenceNumber === null) {
                throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['licence_number' => [__('validation.required', ['attribute' => __('validation.attributes.licence_number')])]]]);
            }
            $check = $this->rega->verifyAdLicence($licenceNumber);
            if (! $check->valid) {
                throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['licence_number' => ['رقم الترخيص الإعلاني غير صالح لدى الهيئة.']]]);
            }
        }

        return DB::transaction(function () use ($customer, $columns, $mediaIds, $coverId, $case, $licenceNumber, $check) {
            $listing = Listing::query()->create([
                ...$columns,
                'customer_id' => $customer->id,
                'status' => ListingStatus::Indexing,
                'text_review' => TextReview::Pending,
                'last_activity_at' => now(),
            ]);
            $listing->forceFill(['image_hash' => MediaLibrary::sync($listing, $customer, $mediaIds, $coverId)])->save();

            if ($case !== null) {
                $case->licence->forceFill(['listing_id' => $listing->id])->save();
                $case->forceFill(['listing_id' => $listing->id, 'stage' => OwnerCaseStage::Active])->save();
            } else {
                AdLicense::query()->create([
                    'listing_id' => $listing->id,
                    'customer_id' => $customer->id,
                    'licence_number' => $licenceNumber,
                    'qr_url' => $check->qrUrl,
                    'source' => LicenceSource::Entered,
                    'status' => LicenceStatus::Valid,
                    'verified_at' => now(),
                    'expires_at' => $check->expiresAt,
                ]);
            }

            ListingHistory::query()->create([
                'listing_id' => $listing->id, 'field' => 'status', 'old_value' => null, 'new_value' => ListingStatus::Indexing->value,
                'actor' => (string) Actor::customer($customer), 'changed_at' => now(),
            ]);
            CleanListingText::dispatch($listing->id)->afterCommit();

            return $listing;
        });
    }
}
