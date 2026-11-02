<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** A local term and its synonyms («حوش» → «فناء», «حديقة داخلية»), used by App\Ai\Retrieval\QueryExpander. */
class SearchTerm extends Model
{
    public const CACHE_KEY = 'zon.search_terms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['synonyms' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
