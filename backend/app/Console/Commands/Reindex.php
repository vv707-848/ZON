<?php

namespace App\Console\Commands;

use App\Enums\IndexEntity;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Indexing\IndexSync;
use App\Models\KbDocument;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * `php artisan zon:reindex [entity] [--force]` — sends what the public can see to the search index
 * again, through the normal outbox (IndexSync → index_events → PushIndexEvent on queue `index`).
 * After an empty or rebuilt Qdrant (`zon-agents bootstrap --recreate`) run it with --force: the
 * unchanged records are sent too. Without --force only records whose indexed text changed go out.
 */
class Reindex extends Command
{
    protected $signature = 'zon:reindex
        {entity? : listing · project_unit · request · kb_document — all when omitted}
        {--force : also resend records the index already has}';

    protected $description = 'Sends listings, project units, requests and knowledge documents to the search index again';

    public function handle(IndexSync $index): int
    {
        $entity = $this->argument('entity');
        $entities = $entity === null ? IndexEntity::cases() : [IndexEntity::tryFrom((string) $entity)];
        if (in_array(null, $entities, true)) {
            $this->error('Unknown entity. One of: '.implode(', ', IndexEntity::values()));

            return self::INVALID;
        }

        $sync = $this->option('force') ? $index->forcing() : $index;
        foreach ($entities as $one) {
            $count = $this->each($this->visible($one), fn (Model $record) => match (true) {
                $record instanceof Listing => $sync->listing($record),
                $record instanceof ProjectUnit => $sync->unit($record),
                $record instanceof SearchRequest => $sync->request($record),
                $record instanceof KbDocument => $sync->kbDocument($record),
                default => null,
            });
            $this->line("{$one->value}: {$count}");
        }
        $this->info('Signals are on queue `index`; follow them in the admin panel (مراقبة الفهرسة).');

        return self::SUCCESS;
    }

    /** @return Builder<covariant Model> the records the index should hold (IndexSync decides per record) */
    private function visible(IndexEntity $entity): Builder
    {
        return match ($entity) {
            IndexEntity::Listing => Listing::query()->live(),
            IndexEntity::ProjectUnit => ProjectUnit::query()->with('project')
                ->whereHas('project', fn (Builder $q) => $q->where('status', ListingStatus::Published)),
            IndexEntity::Request => SearchRequest::query()->where('status', RequestStatus::Published),
            IndexEntity::KbDocument => KbDocument::query()->where('is_active', true),
        };
    }

    /** @param Builder<covariant Model> $query */
    private function each(Builder $query, callable $send): int
    {
        $count = 0;
        $query->chunkById(200, function (Collection $records) use ($send, &$count) {
            foreach ($records as $record) {
                $send($record);
                $count++;
            }
        });

        return $count;
    }
}
