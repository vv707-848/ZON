<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Enums\StaffType;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /** The panel keeps one active superadmin at least: none could manage staff or roles again. */
    protected function beforeSave(): void
    {
        $staff = Record::as($this->getRecord(), User::class);
        $staysSuper = ($this->data['type'] ?? $staff->type->value) === StaffType::Superadmin->value && (bool) ($this->data['is_active'] ?? $staff->is_active);
        if (! $staff->isSuperadmin() || ! $staff->is_active || $staysSuper) {
            return;
        }
        $others = User::query()->where('type', StaffType::Superadmin)->where('is_active', true)->whereKeyNot($staff->getKey())->exists();
        if (! $others) {
            throw ValidationException::withMessages(['data.type' => 'هذا آخر مدير عام نشط؛ عيّن مديراً عاماً آخر أولاً.']);
        }
    }

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
