<?php

namespace App\Support\Settings;

use App\Models\SettingValue;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Reads and writes the business values of Setting (one cached map; written values flush it). */
final class Settings
{
    private const CACHE_KEY = 'zon.settings';

    public static function get(Setting $setting): mixed
    {
        $overrides = Cache::rememberForever(self::CACHE_KEY, fn () => SettingValue::query()->pluck('value', 'key')->all());

        return array_key_exists($setting->value, $overrides) ? $overrides[$setting->value] : $setting->default();
    }

    public static function int(Setting $setting): int
    {
        return (int) self::get($setting);
    }

    public static function bool(Setting $setting): bool
    {
        return (bool) self::get($setting);
    }

    public static function string(Setting $setting): string
    {
        return (string) self::get($setting);
    }

    public static function money(Setting $setting): Money
    {
        return new Money(self::int($setting));
    }

    /** @return list<string> */
    public static function list(Setting $setting): array
    {
        return array_values(array_filter(array_map('trim', (array) self::get($setting)), fn ($v) => $v !== ''));
    }

    /** Stores a value after checking its type and minimum; the audit entry is written by the caller (UpdateSettings). */
    public static function put(Setting $setting, mixed $value, ?User $by = null): void
    {
        $value = match ($setting->type()) {
            'bool' => (bool) $value,
            'int', 'money' => self::wholeNumber($setting, $value),
            'decimal' => is_numeric($value) && (float) $value >= 0 ? (string) $value : throw new InvalidArgumentException($setting->value),
            'list' => array_values(array_filter(array_map('trim', is_array($value) ? $value : (preg_split('/\R/u', (string) $value) ?: [])))),
            default => trim((string) $value),
        };

        SettingValue::query()->updateOrCreate(['key' => $setting->value], ['value' => $value, 'updated_by' => $by?->id, 'updated_at' => now()]);
        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function wholeNumber(Setting $setting, mixed $value): int
    {
        if (! is_numeric($value) || (int) $value != $value) {
            throw new InvalidArgumentException("{$setting->value}: not a whole number");
        }
        if ($setting->minimum() !== null && (int) $value < $setting->minimum()) {
            throw new InvalidArgumentException("{$setting->value}: under {$setting->minimum()}");
        }

        return (int) $value;
    }
}
