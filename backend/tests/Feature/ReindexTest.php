<?php

namespace Tests\Feature;

use App\Enums\IndexAction;
use App\Enums\IndexEntity;
use App\Indexing\IndexSync;
use App\Models\IndexEvent;
use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `zon:reindex` refills the search index through the normal outbox. */
class ReindexTest extends TestCase
{
    public function test_a_rebuilt_index_gets_every_visible_record_again_only_when_forced(): void
    {
        Http::fake(['agents.test/index/*' => Http::response(['status' => 'indexed'])]);
        $published = Listing::factory()->published()->create();
        $draft = Listing::factory()->create();
        app(IndexSync::class)->listing($published);
        $signals = fn () => IndexEvent::query()->where('entity', IndexEntity::Listing)->orderBy('version')->get();

        $this->artisan('zon:reindex listing')->expectsOutput('listing: 1')->assertSuccessful();
        $this->assertCount(1, $signals(), 'unchanged records are not sent again');

        $this->artisan('zon:reindex listing --force')->assertSuccessful();
        $this->assertSame([[IndexAction::Create, 1], [IndexAction::Update, 2]], $signals()->map(fn (IndexEvent $e) => [$e->action, $e->version])->all());
        $this->assertNotNull($signals()->last()->acked_at);
        $this->assertFalse(IndexEvent::query()->where('entity_id', $draft->id)->exists(), 'a draft never reaches the index');

        $this->artisan('zon:reindex listings')->assertExitCode(2);
    }
}
