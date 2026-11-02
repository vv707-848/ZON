<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Record;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $role = Record::as($this->getRecord(), Role::class);
        Audit::log('role_created', 'إنشاء دور', $role, ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')->all()]);
    }
}
