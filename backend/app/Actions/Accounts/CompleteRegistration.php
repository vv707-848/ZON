<?php

namespace App\Actions\Accounts;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Enums\FalStatus;
use App\Exceptions\ApiException;
use App\Jobs\CheckBrokerLicence;
use App\Models\Customer;
use App\Support\Otp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Step 2: the code is right → the account exists. A developer starts «pending» until staff record his marketing
 * contract (he can browse, not publish); a broker's FAL licence is checked with REGA in the background.
 */
final class CompleteRegistration
{
    public function __construct(private readonly Otp $otp) {}

    public function __invoke(string $phone, string $code): Customer
    {
        $form = Cache::get(StartRegistration::key($phone));
        if (! is_array($form) || ! $this->otp->verify($phone, Otp::REGISTER, $code)) {
            throw ApiException::of(ErrorCode::OtpInvalid);
        }

        $role = CustomerRole::from($form['role']);
        try {
            $customer = Customer::query()->create([
                ...$form,
                'status' => $role === CustomerRole::Developer ? AccountStatus::Pending : AccountStatus::Active,
                'fal_status' => $role === CustomerRole::Broker && $form['fal_number'] ? FalStatus::Pending : FalStatus::None,
                'fal_number' => $role === CustomerRole::Broker ? $form['fal_number'] : null,
                'phone_verified_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['username' => [__('validation.unique', ['attribute' => __('validation.attributes.username')])]]]);
        }
        Cache::forget(StartRegistration::key($phone));

        activity('accounts')->performedOn($customer)->causedBy($customer)->event('registered')->log('حساب جديد');
        if ($customer->fal_status === FalStatus::Pending) {
            CheckBrokerLicence::dispatch($customer->id);
        }

        return $customer;
    }
}
