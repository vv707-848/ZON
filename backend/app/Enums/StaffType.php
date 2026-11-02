<?php

namespace App\Enums;

/** Staff of the admin panel (users.type). */
enum StaffType: string
{
    use Concerns;

    case Superadmin = 'superadmin';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'المدير العام',
            self::Admin => 'مشرف',
        };
    }
}
