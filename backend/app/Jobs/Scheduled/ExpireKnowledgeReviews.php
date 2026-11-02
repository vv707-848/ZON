<?php

namespace App\Jobs\Scheduled;

use App\Indexing\IndexSync;
use App\Jobs\ScheduledJob;
use App\Models\KbDocument;

/** A document past its review date leaves the chat's retrieval (an index delete) until someone reviews it. */
class ExpireKnowledgeReviews extends ScheduledJob
{
    public function handle(): void
    {
        $index = app(IndexSync::class);
        KbDocument::query()->where('is_active', true)->whereDate('next_review', '<', today())
            ->each(fn (KbDocument $document) => $index->kbDocument($document));
    }
}
