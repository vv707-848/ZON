<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «تذكرة دعم» from the app's settings: a reference number for the user, a reply from staff. */
class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['status' => TicketStatus::class, 'replied_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
