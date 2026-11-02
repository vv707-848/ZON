<?php

namespace App\Actions\Admin;

use App\Enums\AccountStatus;
use App\Filament\Support\Audit;
use App\Models\Customer;

/** Suspending signs the account out of every phone at once; reactivating needs no new sign-up. */
final class ModerateAccount
{
    public function suspend(Customer $customer, string $reason): void
    {
        $customer->forceFill(['status' => AccountStatus::Suspended, 'suspended_reason' => $reason])->save();
        $customer->tokens()->delete();
        Audit::log('suspended', 'إيقاف حساب', $customer, ['reason' => $reason]);
    }

    public function activate(Customer $customer): void
    {
        $customer->forceFill(['status' => AccountStatus::Active, 'suspended_reason' => null])->save();
        Audit::log('activated', 'تفعيل حساب', $customer);
    }
}
