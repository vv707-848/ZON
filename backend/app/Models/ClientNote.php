<?php

namespace App\Models;

use App\Enums\NoteCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category' => NoteCategory::class];
    }

    /** @return BelongsTo<CrmClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(CrmClient::class, 'client_id');
    }
}
