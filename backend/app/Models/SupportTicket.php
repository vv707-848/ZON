<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A support thread: a complaint, a suggestion or a question from a user (or a visitor from the website), or a
 * message staff start («رسالة من فريق زون»). The user sees staff as «فريق زون», never by name. Every change goes
 * through App\Actions\Support\SupportDesk.
 *
 * Status: open = staff's turn · answered = the user's turn · closed (with its resolution).
 */
class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'type' => 'question', 'opened_by' => 'customer'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'type' => TicketType::class,
            'first_response_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        return 'ZT-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
    }

    /** A message from the other side after the last one this side has read (by id: two in the same second stay apart). */
    public function unreadBy(string $side): bool
    {
        $seen = (int) ($side === 'customer' ? $this->customer_seen_id : $this->staff_seen_id);

        return $this->messages()->where('author', $side === 'customer' ? 'staff' : 'customer')->where('id', '>', $seen)->exists();
    }

    /** This side has read everything so far. */
    public function markSeenBy(string $side): void
    {
        $this->forceFill([$side === 'customer' ? 'customer_seen_id' : 'staff_seen_id' => $this->messages()->max('id')])->save();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** @return HasOne<SupportMessage, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class)->latestOfMany();
    }

    /** @return MorphTo<Model, $this> */
    public function about(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function openedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_staff_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
