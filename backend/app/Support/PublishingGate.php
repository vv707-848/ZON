<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Enums\FalStatus;
use App\Enums\NafathStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/** Who may publish (backend doc §7): role, account active, FAL for brokers, Nafath once. The plan is checked by the caller. */
final class PublishingGate
{
    public static function assert(Customer $customer, CustomerRole ...$roles): void
    {
        if (! $customer->role->canPublish() || ($roles !== [] && ! $customer->hasRole(...$roles))) {
            throw ApiException::of(ErrorCode::RoleForbidden);
        }
        if ($customer->status === AccountStatus::Pending) {
            throw ApiException::of(ErrorCode::AccountPending);
        }
        if ($customer->role === CustomerRole::Broker && ($customer->fal_status !== FalStatus::Valid || ($customer->fal_expires_at?->isPast() ?? false))) {
            throw ApiException::of(ErrorCode::FalRequired);
        }
        if (Settings::bool(Setting::RequireNafath) && $customer->nafath_status !== NafathStatus::Verified) {
            throw ApiException::of(ErrorCode::NafathRequired);
        }
    }
}
