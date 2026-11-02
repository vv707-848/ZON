<?php

namespace App\Actions\Accounts;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\Otp;

/** «نسيت كلمة السر»: a code to the phone, then a new password; every signed-in phone is signed out. */
final class ResetPassword
{
    public function __construct(private readonly Otp $otp) {}

    /** Sends the code only when the number has an account; the answer never says which. */
    public function start(string $phone): void
    {
        if (Customer::query()->where('phone', $phone)->whereNull('anonymized_at')->exists()) {
            $this->otp->send($phone, Otp::RESET);
        }
    }

    public function finish(string $phone, string $code, string $password): void
    {
        $customer = Customer::query()->where('phone', $phone)->whereNull('anonymized_at')->first();
        if ($customer === null || ! $this->otp->verify($phone, Otp::RESET, $code)) {
            throw ApiException::of(ErrorCode::OtpInvalid);
        }

        $customer->forceFill(['password' => $password])->save();
        $customer->tokens()->delete();
        activity('accounts')->performedOn($customer)->causedBy($customer)->event('password_reset')->log('استعادة كلمة السر');
    }
}
