<?php

namespace App\Models;

use App\Enums\AiAgent;
use App\Enums\AiCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One AI call: who, which agent, outcome, cost, latency and the trace id — never the personal text itself. */
class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['agent' => AiAgent::class, 'status' => AiCallStatus::class];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
