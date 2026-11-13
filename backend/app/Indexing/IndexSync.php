<?php

namespace App\Indexing;

use App\Enums\IndexAction;
use App\Enums\IndexEntity;
use App\Enums\ListingStatus;
use App\Jobs\PushIndexEvent;
use App\Models\IndexEvent;
use App\Models\KbDocument;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use App\Support\Trace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * The index outbox (backend doc §10). Called inside the transaction of every change that affects search:
 * it decides create / update / delete, writes the signal with version = index_version + 1, and queues
 * PushIndexEvent after the commit (a queue that cannot take it fails nothing: the signal is sent again). Same payload as the last signal → nothing (unless forcing(): zon:reindex).
 * Whatever is not public is sent as `delete`.
 */
final class IndexSync
{
    private bool $force = false;

    /** A copy that re-sends unchanged records (after the index was rebuilt). */
    public function forcing(): self
    {
        $copy = clone $this;
        $copy->force = true;

        return $copy;
    }

    public function listing(Listing $listing): ?IndexEvent
    {
        $public = ! $listing->trashed() && $listing->isLive();

        return $this->signal(IndexEntity::Listing, $listing, $public ? IndexPayloads::listing($listing) : null);
    }

    public function unit(ProjectUnit $unit): ?IndexEvent
    {
        $project = $unit->project;
        $public = $project->status === ListingStatus::Published && ! $project->trashed() && $unit->isSearchable();

        return $this->signal(IndexEntity::ProjectUnit, $unit, $public ? IndexPayloads::unit($unit) : null);
    }

    public function request(SearchRequest $request): ?IndexEvent
    {
        return $this->signal(IndexEntity::Request, $request, $request->status->value === 'published' ? IndexPayloads::request($request) : null);
    }

    public function kbDocument(KbDocument $document): ?IndexEvent
    {
        $live = $document->is_active && ! $document->next_review->isPast();

        return $this->signal(IndexEntity::KbDocument, $document, $live ? IndexPayloads::kbDocument($document) : null);
    }

    /** @param ?array<string, mixed> $payload null = the record must not be in the index */
    private function signal(IndexEntity $entity, Model $record, ?array $payload): ?IndexEvent
    {
        return DB::transaction(function () use ($entity, $record, $payload) {
            $last = IndexEvent::query()->where('entity', $entity)->where('entity_id', $record->getKey())->orderByDesc('version')->lockForUpdate()->first();
            $indexed = $last !== null && $last->action !== IndexAction::Delete;
            $hash = sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

            if ($payload === null && ! $indexed) {
                return null;                                                   // never indexed, nothing to remove
            }
            if ($payload !== null && $indexed && $last->payload_hash === $hash && ! $this->force) {
                return null;                                                   // the index already has this
            }

            $version = max((int) $record->getAttribute('index_version'), (int) $last?->version) + 1;
            $record->forceFill(['index_version' => $version])->saveQuietly();

            $event = IndexEvent::query()->create([
                'entity' => $entity,
                'entity_id' => $record->getKey(),
                'action' => $payload === null ? IndexAction::Delete : ($indexed ? IndexAction::Update : IndexAction::Create),
                'version' => $version,
                'payload' => $payload,
                'payload_hash' => $hash,
                'trace_id' => Trace::id(),
                'created_at' => now(),
            ]);
            // after the commit; a queue that cannot take it fails nothing saved: RetryPendingIndexEvents sends
            // every signal not taken within five minutes
            DB::afterCommit(fn () => rescue(fn () => Bus::dispatch(new PushIndexEvent($event->id)), report: true));

            return $event;
        });
    }
}
