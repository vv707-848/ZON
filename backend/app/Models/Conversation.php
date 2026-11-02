<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One conversation per pair of accounts and subject (a listing, a project, a request, or none). */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Message, $this> one query for a whole page of conversations (the list's last line) */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** @return BelongsTo<SearchRequest, $this> */
    public function searchRequest(): BelongsTo
    {
        return $this->belongsTo(SearchRequest::class, 'request_id');
    }
}
