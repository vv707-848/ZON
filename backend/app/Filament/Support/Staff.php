<?php

namespace App\Filament\Support;

use App\Models\User;
use App\Support\Permissions\Permission;
use Filament\Facades\Filament;
use RuntimeException;

/** The signed-in member of staff and what he may do (the superadmin may do everything). */
final class Staff
{
    public static function user(): User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : throw new RuntimeException('No staff member is signed in.');
    }

    public static function can(Permission $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_active && ($user->isSuperadmin() || $user->can($permission->value));
    }

    public static function isSuperadmin(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_active && $user->isSuperadmin();
    }
}
