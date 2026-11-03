<?php

namespace App\Actions\Billing;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/**
 * Checkout: the SERVER prices (never the app), checks what is bought belongs to the buyer and can take it
 * (PurchaseTarget), and reuses the same pending payment for 30 minutes (two taps never make two charges). VAT is
 * split out only while the platform is VAT-registered, and never on the advertising licence fee (passed on to REGA).
 */
final class StartCheckout
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly PurchaseTarget $targets) {}

    /** @param ?array{type: string, id: int} $target */
    public function __invoke(Customer $customer, PaymentPurpose $purpose, ?string $packageCode, ?array $target): Payment
    {
        [$model, $price] = $this->targets->resolve($customer, $purpose, $packageCode, $target);

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
}
