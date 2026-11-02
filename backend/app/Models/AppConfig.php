<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Forced update and maintenance mode of one platform (ios · android), read on every app request. */
class AppConfig extends Model
{
    public const CACHE_KEY = 'zon.app_configs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['maintenance_mode' => 'boolean', 'maintenance_until' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function for(string $platform): ?self
    {
        // The cache holds the raw columns: it never returns PHP objects (config/cache.php serializable_classes).
        /** @var array<string, array<string, mixed>> $all */
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->get()
            ->mapWithKeys(fn (self $config) => [$config->platform => $config->getAttributes()])->all());

        return isset($all[$platform]) ? (new self)->newFromBuilder($all[$platform]) : null;
    }

    public function inMaintenance(): bool
    {
        return $this->maintenance_mode && ($this->maintenance_until === null || $this->maintenance_until->isFuture());
    }
}
