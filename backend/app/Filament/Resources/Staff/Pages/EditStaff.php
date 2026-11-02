<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /** What changed on a staff account (roles included) — the password only as «changed». */
    protected function afterSave(): void
    {
        $staff = Record::as($this->getRecord(), User::class);
        $changed = array_keys($staff->getChanges());
        Audit::log('staff_updated', 'تعديل حساب موظف', $staff, [
            'fields' => array_values(array_diff($changed, ['password', 'remember_token', 'updated_at'])),
            'password_changed' => in_array('password', $changed, true),
            'roles' => $staff->roles()->pluck('name')->all(),
        ]);
    }
}
