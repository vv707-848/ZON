<?php

namespace App\Models;

use App\Enums\AnnouncementKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notice from staff to all accounts, a role or a city — a service message or a promotion (App\Enums\AnnouncementKind). */
class Announcement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'kind' => AnnouncementKind::class];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
