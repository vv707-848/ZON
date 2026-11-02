<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /** Who may now enter the panel, and with which roles — the password is never logged. */
    protected function afterCreate(): void
    {
        $staff = Record::as($this->getRecord(), User::class);
        Audit::log('staff_created', 'إنشاء حساب موظف', $staff, ['email' => $staff->email, 'type' => $staff->type->value, 'roles' => $staff->roles->pluck('name')->all()]);
    }
}
