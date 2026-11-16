<?php

namespace App\Models;

use App\Enums\IndexAction;
use App\Enums\IndexEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A signal of the index outbox: committed with the change, sent by PushIndexEvent, acknowledged by the agents
 * service. sent_at empty = not sent by the backend · acked_at empty = not taken by the index.
 */
class IndexEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entity' => IndexEntity::class,
            'action' => IndexAction::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }

    /** @param Builder<IndexEvent> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('acked_at');
    }

    /**
     * Every try of PushIndexEvent failed, or the service refused it (a 4xx is final): RetryPendingIndexEvents sends
     * it again up to `zon.index.max_attempts`, a refused one never; «إعادة الإرسال» in the panel always can.
     *
     * @param  Builder<IndexEvent>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->whereNull('acked_at')->whereNotNull('last_error')
            ->where(fn (Builder $q) => $q->where('attempts', '>=', 3)->orWhere('last_error', 'like', 'HTTP 4%'));
    }
}
