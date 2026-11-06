<?php

namespace App\Models;

use App\Enums\Region;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A city offered in the app's filters and forms (the app's kCities, now edited from the panel). Its region is for the
 * market map alone, and stays out of the app's catalog.
 */
class City extends Model
{
    public const CACHE_KEY = 'zon.cities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'region' => Region::class];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return HasMany<Neighborhood, $this> */
    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    /**
     * Active cities and their active neighbourhoods, as the app's catalog: name => [neighbourhood, …].
     *
     * @return array<string, list<string>>
     */
    public static function catalog(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->where('is_active', true)->orderBy('sort')
            ->with(['neighborhoods' => fn ($q) => $q->where('is_active', true)->orderBy('id')])->get()
            ->mapWithKeys(fn (City $city) => [$city->name => $city->neighborhoods->pluck('name')->all()])->all());
    }

    public static function has(string $city, ?string $neighborhood = null): bool
    {
        $catalog = self::catalog();

        return isset($catalog[$city]) && ($neighborhood === null || in_array($neighborhood, $catalog[$city], true));
    }
}
