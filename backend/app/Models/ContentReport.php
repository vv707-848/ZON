<?php

namespace App\Models;

use App\Enums\ReportAction;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** «بلاغ» on a listing, a project, a request, an account, a message or a rating. */
class ContentReport extends Model
{
    /** What may be reported, as the morph names the app sends. */
    public const TARGETS = ['listing', 'project', 'request', 'customer', 'message', 'rating'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'new', 'reason' => 'other'];

    protected function casts(): array
    {
        return ['reason' => ReportReason::class, 'status' => ReportStatus::class, 'action_taken' => ReportAction::class, 'handled_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'reporter_id');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
