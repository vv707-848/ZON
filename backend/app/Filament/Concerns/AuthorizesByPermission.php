<?php

namespace App\Filament\Concerns;

use App\Filament\Support\Staff;
use App\Support\Permissions\Permission;
use Illuminate\Database\Eloquent\Model;

/**
 * Resource access from the panel's permissions: viewPermission() opens the list and the pages; managePermission()
 * (the same unless a resource says otherwise) creates, edits and deletes. Actions check their own permission.
 */
trait AuthorizesByPermission
{
    abstract protected static function viewPermission(): Permission;

    protected static function managePermission(): ?Permission
    {
        return static::viewPermission();
    }

    public static function canViewAny(): bool
    {
        return Staff::can(static::viewPermission());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    /** May create, edit and delete (each resource still turns off what makes no sense for it). */
    protected static function canManage(): bool
    {
        $permission = static::managePermission();

        return $permission !== null && Staff::can($permission);
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage();
    }
}
