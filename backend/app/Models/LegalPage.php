<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A policy page of the app's settings (payments · privacy · ip · terms · rega), versioned. */
class LegalPage extends Model
{
    public const SLUGS = ['payments', 'privacy', 'ip', 'terms', 'rega'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
