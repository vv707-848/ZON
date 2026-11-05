<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/** A policy page of the app's settings and the website (payments · privacy · ip · terms · rega), versioned; shown once published. */
class LegalPage extends Model
{
    public const SLUGS = ['payments', 'privacy', 'ip', 'terms', 'rega'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('site.policies'));       // the website's footer and /legal
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
