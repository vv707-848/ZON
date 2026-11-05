<?php

namespace App\Actions\Accounts;

use App\Enums\AccountStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;

/**
 * «تسجيل الدخول» with the username OR the phone, and the password. Signing in during a deletion's grace period
 * cancels the deletion (store rule) and brings back what it hid.
 */
final class SignIn
{
    public function __construct(private readonly CancelDeletion $cancelDeletion) {}

    public function __invoke(string $login, string $password): Customer
    {
        return $this->complete($this->check($login, $password));
    }

    /** The account behind a username or phone and its password — nothing else happens (the website's deletion form). */
    public function check(string $login, string $password): Customer
    {
        $phone = Phone::normalize($login);
        $customer = Customer::query()
            ->when($phone !== null, fn ($q) => $q->where('phone', $phone), fn ($q) => $q->where('username', trim($login)))
            ->whereNull('anonymized_at')
            ->first();

        if ($customer === null || ! Hash::check($password, $customer->password)) {
            throw ApiException::of(ErrorCode::InvalidCredentials);
        }
        if ($customer->status === AccountStatus::Suspended) {
            throw ApiException::of(ErrorCode::AccountSuspended);
        }

        return $customer;
    }

    /** Signed in: a pending deletion is cancelled (store rule) and the sign-in is logged. */
    public function complete(Customer $customer): Customer
    {
        if ($deletion = $customer->pendingDeletion()->first()) {
            ($this->cancelDeletion)($deletion, "customer:{$customer->id}");
        }
        activity('accounts')->performedOn($customer)->causedBy($customer)->event('signed_in')->log('تسجيل دخول');

        return $customer;
    }
}
