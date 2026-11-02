<?php

namespace App\Actions\Accounts;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\Otp;
use Illuminate\Database\UniqueConstraintViolationException;

/** «تعديل معلوماتي» → a new phone takes effect only after its own code. */
final class ChangePhone
{
    public function __construct(private readonly Otp $otp) {}

    public function start(Customer $customer, string $phone): void
    {
        $this->otp->send($phone, Otp::PHONE_CHANGE.":{$customer->id}");
    }

    public function finish(Customer $customer, string $phone, string $code): void
    {
        if (! $this->otp->verify($phone, Otp::PHONE_CHANGE.":{$customer->id}", $code)) {
            throw ApiException::of(ErrorCode::OtpInvalid);
        }
        $old = $customer->phone;
        try {
            $customer->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['phone' => [__('validation.unique', ['attribute' => __('validation.attributes.phone')])]]]);
        }
        activity('accounts')->performedOn($customer)->causedBy($customer)->event('phone_changed')
            ->withProperties(['from' => substr($old, -3), 'to' => substr($phone, -3)])->log('تغيير رقم الجوال');
    }
}
