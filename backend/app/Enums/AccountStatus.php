<?php

namespace App\Enums;

/** customers.status: `pending` = a developer waiting for his marketing contract. */
enum AccountStatus: string
{
    use Concerns;

    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Pending => 'بانتظار التفعيل',
            self::Suspended => 'موقوف',
        };
    }
}
