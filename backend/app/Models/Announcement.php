<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notice from staff to all accounts, a role or a city (sent by App\Jobs\SendAnnouncement). */
class Announcement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
