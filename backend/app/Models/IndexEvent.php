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
     * Every try of PushIndexEvent failed: waits for RetryPendingIndexEvents or «إعادة الإرسال» in the panel.
     *
     * @param  Builder<IndexEvent>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->whereNull('acked_at')->where('attempts', '>=', 3)->whereNotNull('last_error');
    }
}
