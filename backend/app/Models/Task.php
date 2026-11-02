<?php

namespace App\Models;

use App\Enums\TaskPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «مهامي»: a to-do with an optional reminder (SendTaskReminders every 5 minutes). */
class Task extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['priority' => 'medium'];

    protected function casts(): array
    {
        return ['priority' => TaskPriority::class, 'remind_at' => 'datetime', 'reminded_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
