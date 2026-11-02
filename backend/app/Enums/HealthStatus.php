<?php

namespace App\Enums;

/** Outcome of one system check (App\Support\Health\SystemHealth). */
enum HealthStatus: string
{
    use Concerns;

    case Ok = 'ok';
    case Warn = 'warn';     // works, but needs attention soon
    case Fail = 'fail';     // broken: a part of the platform is not working

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'سليم',
            self::Warn => 'تنبيه',
            self::Fail => 'عطل',
        };
    }

    /** Badge colour in the admin panel. */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warn => 'warning',
            self::Fail => 'danger',
        };
    }

    /** Mark in the `zon:doctor` table. */
    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✓',
            self::Warn => '!',
            self::Fail => '✗',
        };
    }
}
