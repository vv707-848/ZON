<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** A role's permissions after the change: who can do what is always traceable. */
    protected function afterSave(): void
    {
        $role = Record::as($this->getRecord(), Role::class);
        Audit::log('role_updated', 'تعديل دور', $role, ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')->all()]);
    }
}
