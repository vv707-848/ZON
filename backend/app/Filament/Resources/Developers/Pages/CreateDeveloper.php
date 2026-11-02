<?php

namespace App\Filament\Resources\Developers\Pages;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\NafathStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Developers\DeveloperResource;
use App\Filament\Support\Audit;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** A developer account made by staff: pending until its marketing contract is recorded. */
class CreateDeveloper extends CreateRecord
{
    protected static string $resource = DeveloperResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $developer = Customer::query()->create([
            ...$data,
            'role' => CustomerRole::Developer,
            'status' => AccountStatus::Pending,
            'nafath_status' => NafathStatus::None,
            'phone_verified_at' => now(),
        ]);
        Audit::log('developer_created', 'إنشاء حساب مطوّر', $developer);

        return $developer;
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
