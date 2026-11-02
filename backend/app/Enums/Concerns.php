<?php

namespace App\Enums;

/**
 * Shared helpers of the string-backed enums of this folder.
 *
 * Every value is the exact string stored in the database and sent in the API; label() is the Arabic text
 * shown in the app and the admin panel.
 */
trait Concerns
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return array<string, string> value => Arabic label (admin selects) */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
