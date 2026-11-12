<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A policy page of the app's settings and the website, versioned; shown once published. The five the app opens by
 * name (SLUGS) always exist; staff add others (a refund policy, advertising rules…), which the website lists after
 * the five and the app reads from `GET /legal`.
 */
class LegalPage extends Model
{
    public const SLUGS = ['payments', 'privacy', 'ip', 'terms', 'rega'];

    /** A new page's address: lower-case Latin letters, digits and dashes (`refunds`, `ad-rules`). */
    public const SLUG_PATTERN = '/^[a-z][a-z0-9-]{1,19}$/';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('site.policies'));       // the website's footer and /legal
        static::deleted(fn () => Cache::forget('site.policies'));
    }

    /** One of the five the app opens by name: never removed. */
    public function isFixed(): bool
    {
        return in_array($this->slug, self::SLUGS, true);
    }

    /**
     * Published pages, the five first in their order, then the others by title.
     *
     * @param  Builder<LegalPage>  $query
     */
    public function scopePublishedInOrder(Builder $query): void
    {
        $fixed = implode(', ', array_map(fn (string $s) => "'{$s}'", self::SLUGS));
        $query->whereNotNull('published_at')->orderByRaw("FIELD(slug, {$fixed}) = 0")->orderByRaw("FIELD(slug, {$fixed})")->orderBy('title');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
