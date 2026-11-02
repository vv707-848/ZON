<?php

namespace App\Actions\Billing;

use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\OwnerCaseStage;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Payment;
use App\Models\SearchRequest;
use App\Support\Money;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * Checkout: the SERVER prices (never the app), checks what is bought belongs to the buyer and can take it, and
 * reuses the same pending payment for 30 minutes (two taps never make two charges). VAT is split out only while
 * the platform is VAT-registered, and never on the advertising licence fee (passed on to REGA).
 */
final class StartCheckout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @param ?array{type: string, id: int} $target */
    public function __invoke(Customer $customer, PaymentPurpose $purpose, ?string $packageCode, ?array $target): Payment
    {
        [$model, $price] = match ($purpose) {
            PaymentPurpose::Subscription => $this->package($customer, $packageCode),
            PaymentPurpose::Featured, PaymentPurpose::UrgentListing => [$this->listing($customer, $target, BoostKind::from($purpose->value)), ApplyBoost::price(BoostKind::from($purpose->value))],
            PaymentPurpose::UrgentRequest => [$this->request($customer, $target), ApplyBoost::price(BoostKind::UrgentRequest)],
            PaymentPurpose::AdLicence => [$this->ownerCase($customer, $target), Settings::money(Setting::AdLicenceFee)],
        };

        $pending = Payment::query()->where('customer_id', $customer->id)->where('purpose', $purpose)->where('status', PaymentStatus::Pending)
            ->where('target_type', $model->getMorphClass())->where('target_id', $model->getKey())->where('amount_halalas', $price->halalas)
            ->where('created_at', '>', now()->subMinutes((int) config('zon.payments.reuse_pending_minutes')))->latest('id')->first();
        if ($pending !== null) {
            return $pending;
        }

        $taxed = Settings::bool(Setting::VatRegistered) && $purpose !== PaymentPurpose::AdLicence;

        return Payment::query()->create([
            'customer_id' => $customer->id,
            'purpose' => $purpose,
            'target_type' => $model->getMorphClass(),
            'target_id' => $model->getKey(),
            'amount_halalas' => $price->halalas,
            'vat_halalas' => $taxed ? $price->withoutRate(Settings::string(Setting::VatRate))['vat']->halalas : 0,
            'provider' => $this->gateway->name(),
            'status' => PaymentStatus::Pending,
        ]);
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
