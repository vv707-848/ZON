<?php

namespace App\Models;

use App\Enums\ChatIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A turn of the chat «زون»: the masked question, the answer, what guarded it. */
class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['intent' => ChatIntent::class, 'kb_doc_ids' => 'array'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
