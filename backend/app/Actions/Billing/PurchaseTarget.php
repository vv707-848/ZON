<?php

namespace App\Actions\Billing;

use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Enums\OwnerCaseStage;
use App\Enums\PaymentPurpose;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Support\Money;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * What a purchase buys, and whether it can take it now: a plan of the buyer's own role, or his own live listing
 * (not already featured / urgent), published project (not already featured), published request (not already
 * urgent) or owner's case at its fee step. Shared
 * by the website's checkout (Moyasar, priced here) and the apps' store purchases (priced by the store).
 */
final class PurchaseTarget
{
    /**
     * @param  ?array{type: string, id: int}  $target
     * @return array{0: Model, 1: Money} the target and its listed price
     */
    public function resolve(Customer $customer, PaymentPurpose $purpose, ?string $packageCode, ?array $target): array
    {
        return match ($purpose) {
            PaymentPurpose::Subscription => $this->package($customer, $packageCode),
            PaymentPurpose::Featured => ($target['type'] ?? null) === 'project'
                ? [$project = $this->project($customer, $target), ApplyBoost::price(BoostKind::Featured, $project)]
                : [$this->listing($customer, $target, BoostKind::Featured), ApplyBoost::price(BoostKind::Featured)],
            PaymentPurpose::UrgentListing => [$this->listing($customer, $target, BoostKind::UrgentListing), ApplyBoost::price(BoostKind::UrgentListing)],
            PaymentPurpose::UrgentRequest => [$this->request($customer, $target), ApplyBoost::price(BoostKind::UrgentRequest)],
            PaymentPurpose::AdLicence => [$this->ownerCase($customer, $target), Settings::money(Setting::AdLicenceFee)],
        };
    }

    /** @return array{0: Package, 1: Money} */
    private function package(Customer $customer, ?string $code): array
    {
        $package = Package::query()->where('code', (string) $code)->first();
        if ($package === null || ! $package->isPurchasable() || $package->audience->value !== $customer->role->value) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['package' => [__('validation.in', ['attribute' => __('validation.attributes.package')])]]]);
        }

        return [$package, $package->price()];
    }

    /** @param ?array{type: string, id: int} $target */
    private function listing(Customer $customer, ?array $target, BoostKind $kind): Listing
    {
        $listing = $this->owned($customer, $target, 'listing', Listing::class);
        $running = $kind === BoostKind::Featured ? $listing->isFeatured() : $listing->isUrgent();
        if (! $listing->isLive() || $running) {
            throw ApiException::of($running ? ErrorCode::AlreadyActive : ErrorCode::InvalidTransition);
        }

        return $listing;
    }

    /** @param ?array{type: string, id: int} $target */
    private function project(Customer $customer, ?array $target): Project
    {
        $project = Project::query()->find((int) ($target['id'] ?? 0));
        if ($project === null || $project->developer_id !== $customer->id) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        $running = $project->featured_until?->isFuture() ?? false;
        if ($project->status !== ListingStatus::Published || $running) {
            throw ApiException::of($running ? ErrorCode::AlreadyActive : ErrorCode::InvalidTransition);
        }

        return $project;
    }

    /** @param ?array{type: string, id: int} $target */
    private function request(Customer $customer, ?array $target): SearchRequest
    {
        $request = $this->owned($customer, $target, 'request', SearchRequest::class);
        if ($request->status !== RequestStatus::Published || $request->urgent) {
            throw ApiException::of($request->urgent ? ErrorCode::AlreadyActive : ErrorCode::InvalidTransition);
        }

        return $request;
    }

    /** @param ?array{type: string, id: int} $target */
    private function ownerCase(Customer $customer, ?array $target): OwnerCase
    {
        $case = $this->owned($customer, $target, 'owner_case', OwnerCase::class);
        if ($case->stage !== OwnerCaseStage::Fee) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }

        return $case;
    }

    /**
     * @template T of Model
     *
     * @param  ?array{type: string, id: int}  $target
     * @param  class-string<T>  $class
     * @return T
     */
    private function owned(Customer $customer, ?array $target, string $type, string $class): Model
    {
        if (($target['type'] ?? null) !== $type) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['target' => [__('validation.required', ['attribute' => 'الهدف'])]]]);
        }
        $model = $class::query()->find((int) $target['id']);
        if ($model === null || (int) $model->getAttribute('customer_id') !== $customer->id) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        return $model;
    }
}
