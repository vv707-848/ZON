<?php

namespace App\Enums;

/** An index signal's action. */
enum IndexAction: string
{
    use Concerns;

    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'إضافة',
            self::Update => 'تحديث',
            self::Delete => 'حذف',
        };
    }
}
