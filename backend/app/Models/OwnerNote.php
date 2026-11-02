<?php

namespace App\Models;

use App\Enums\NoteCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerNote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category' => NoteCategory::class];
    }

    /** @return BelongsTo<PropertyOwner, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(PropertyOwner::class, 'owner_id');
    }
}
