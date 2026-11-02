<?php

namespace App\Filament\Concerns;

use App\Filament\Support\Staff;
use Illuminate\Database\Eloquent\Model;

/** Screens outside any role: the superadmin alone (staff accounts, roles, the SuperAgent, market reports). */
trait SuperadminOnly
{
    public static function canViewAny(): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canView(Model $record): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canCreate(): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canEdit(Model $record): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
