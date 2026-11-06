<?php

namespace Tests\Feature\Api;

use App\Enums\FalStatus;
use App\Enums\ListingStatus;
use App\Models\AiRequest;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\UserBlock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A listing's life: gates → licence → AI wording → the advertiser's decision → public → pause · sold · boost. */
class ListingsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return $extra + [
            'purpose' => 'sale', 'property_type' => 'apartment', 'title' => 'شقة في النرجس', 'city' => 'الرياض', 'neighborhood' => 'النرجس',
            'price' => '٨٨٠,٠٠٠', 'area_built' => 150, 'bedrooms' => 3, 'bathrooms' => 2, 'floor_level' => 'ground', 'has_deed' => true,
            'description' => 'شقة واسعة جداً للتواصل 0551234567 قريبة من الخدمات', 'licence_number' => '7200123456',
        ];
    }

    /** @param array<string, mixed> $output */
    private function agentAnswers(array $output, bool $degraded = false): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['output' => $output, 'degraded' => $degraded, 'usage' => ['tokens_in' => 300, 'tokens_out' => 80], 'latency_ms' => 900])]);
    }

    public function test_a_broker_publishes_after_deciding_on_the_wording(): void
    {
        $this->agentAnswers(['desc_clean' => 'شقة واسعة قريبة من الخدمات.', 'features' => ['دور أرضي']]);
        $broker = $this->signIn(Customer::factory()->broker()->create());

        $id = $this->postJson('/api/v1/listings', $this->form())->assertCreated()->json('data.id');

        $listing = Listing::query()->findOrFail($id);
        $this->assertSame('880000.00', $listing->price, 'Arabic digits and separators read exactly');
        $this->assertSame('pending_review', $listing->status->value);
        $this->assertSame('شقة واسعة قريبة من الخدمات.', $listing->desc_suggested);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/agents/clean_listing') && ! str_contains($r['text'], '0551234567')
            && $r->hasHeader('X-Internal-Key', 'test-internal-key'));
        $this->assertSame(1, AiRequest::query()->where('agent', 'clean_listing')->where('status', 'ok')->count());
        $this->assertFalse(IndexEvent::query()->exists(), 'nothing reaches the index before the decision');

        $this->getJson("/api/v1/listings/{$id}/review")->assertOk()->assertJsonPath('data.suggested', 'شقة واسعة قريبة من الخدمات.');
        $this->postJson("/api/v1/listings/{$id}/review", ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.description', 'شقة واسعة قريبة من الخدمات.');

        $event = IndexEvent::query()->sole();
        $this->assertSame(['create', 1, 'شقة واسعة قريبة من الخدمات.'], [$event->action->value, $event->version, $event->payload['desc_clean']]);
        $this->assertNotNull($event->acked_at);
        $this->assertSame(1, $broker->notifications()->where('type', 'listing')->where('data->key', "published:{$id}")->count());
    }

    public function test_when_the_ai_is_down_the_listing_is_published_as_written(): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['detail' => 'down'], 503)]);
        $this->signIn(Customer::factory()->broker()->create());

        $this->postJson('/api/v1/listings', $this->form())->assertCreated();

        $listing = Listing::query()->sole();
        $this->assertSame(['published', 'auto'], [$listing->status->value, $listing->text_review->value]);
        $this->assertSame('fallback', AiRequest::query()->sole()->status->value);
        $this->assertSame('شقة واسعة جداً للتواصل [رقم] قريبة من الخدمات', IndexEvent::query()->sole()->payload['desc_clean'],
            'the text goes public as written, but the number never reaches the AI node');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/index/') && str_contains($r->body(), '0551234567'));
    }

    public function test_hidden_or_rejected_listings_come_back_only_through_moderation(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        foreach ([ListingStatus::Hidden, ListingStatus::Rejected] as $state) {
            $listing = Listing::factory()->published()->create(['customer_id' => $broker->id, 'status' => $state]);
            $this->postJson("/api/v1/listings/{$listing->id}/resume")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
            $this->postJson("/api/v1/listings/{$listing->id}/pause")->assertStatus(409);      // hidden → paused → resume was a way round
            $this->assertSame($state, $listing->fresh()->status);
        }

        $paused = Listing::factory()->published()->create(['customer_id' => $broker->id, 'status' => ListingStatus::Paused]);
        $this->postJson("/api/v1/listings/{$paused->id}/resume")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_publishing_gates(): void
    {
        $this->signIn(Customer::factory()->broker()->create(['fal_status' => FalStatus::Rejected]));
        $this->postJson('/api/v1/listings', $this->form())->assertStatus(403)->assertJsonPath('error.code', 'FAL_REQUIRED');

        $this->signIn(Customer::factory()->broker()->withoutNafath()->create());
        $this->postJson('/api/v1/listings', $this->form())->assertStatus(403)->assertJsonPath('error.code', 'NAFATH_REQUIRED');

        $this->signIn(Customer::factory()->developer()->create());
        $this->postJson('/api/v1/listings', $this->form())->assertStatus(403)->assertJsonPath('error.code', 'ROLE_FORBIDDEN');

        $this->signIn(Customer::factory()->broker()->create());
        $this->postJson('/api/v1/listings', $this->form(['licence_number' => '7200000000']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['licence_number']]]]);
        $this->postJson('/api/v1/listings', $this->form(['land_use' => 'commercial', 'floors' => 3]))
            ->assertStatus(422)->assertJsonPath('error.details.fields.land_use.0', 'هذا الحقل لا ينطبق على نوع العقار المختار.');
        $this->postJson('/api/v1/listings', $this->form(['neighborhood' => 'الشاطئ']))->assertStatus(422)->assertJsonPath('error.details.fields.neighborhood.0', 'الحي غير موجود في المدينة المختارة.');
    }

    public function test_the_feed_shows_public_listings_only_and_respects_blocks(): void
    {
        $visible = Listing::factory()->published()->create(['price' => 900000]);
        Listing::factory()->create();                                         // a draft
        $blocker = Listing::factory()->published()->create();
        $viewer = Customer::factory()->broker()->create();
        UserBlock::query()->create(['blocker_id' => $blocker->customer_id, 'blocked_id' => $viewer->id]);

        $this->getJson('/api/v1/listings?city=الرياض')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.page.total', 2)->assertJsonPath('data.0.phones', null);

        $this->signIn($viewer);
        $this->getJson('/api/v1/listings')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson("/api/v1/listings/{$blocker->id}")->assertNotFound();
        $this->getJson("/api/v1/listings/{$visible->id}")->assertOk()->assertJsonPath('data.phones.0', '0'.substr($visible->customer->phone, 4));
        $this->getJson('/api/v1/listings?price_max=800000')->assertJsonCount(0, 'data');
    }

    public function test_pause_resume_and_sold_follow_the_state_machine(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->for($broker)->create();

        $this->postJson("/api/v1/listings/{$listing->id}/pause", ['until' => now()->addDays(5)->toDateString()])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/listings/{$listing->id}/resume")->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson("/api/v1/listings/{$listing->id}/sold")->assertOk()->assertJsonPath('data.status', 'sold');
        $this->postJson("/api/v1/listings/{$listing->id}/resume")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->assertSame(['published→paused', 'paused→published', 'published→sold'],
            $listing->history()->reorder('id')->get()->map(fn ($h) => "{$h->old_value}→{$h->new_value}")->all());

        $other = Listing::factory()->published()->create();
        $this->postJson("/api/v1/listings/{$other->id}/pause")->assertNotFound();
    }

    public function test_a_boost_spends_the_plan_balance_or_asks_for_payment(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->for($broker)->create();

        $this->postJson("/api/v1/listings/{$listing->id}/boost", ['kind' => 'featured'])
            ->assertStatus(402)->assertJsonPath('error.details.price', '100.00')->assertJsonPath('error.details.target.type', 'listing');

        Subscription::query()->create(['customer_id' => $broker->id, 'package_id' => Package::query()->where('code', 'broker-month')->value('id'),
            'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->postJson("/api/v1/listings/{$listing->id}/boost", ['kind' => 'featured'])->assertOk()->assertJsonPath('data.via', 'quota');
        $this->postJson("/api/v1/listings/{$listing->id}/boost", ['kind' => 'featured'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ACTIVE');
        $this->getJson('/api/v1/listings')->assertJsonPath('data.0.featured', true);
    }

    public function test_photos_are_uploaded_then_attached_in_order_with_a_cover(): void
    {
        $this->agentAnswers(['desc_clean' => 'وصف']);
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $first = $this->post('/api/v1/media', ['photo' => UploadedFile::fake()->image('a.jpg', 640, 480)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $second = $this->post('/api/v1/media', ['photo' => UploadedFile::fake()->image('b.jpg', 640, 480)], ['Accept' => 'application/json'])->json('data.id');
        $foreign = $this->signIn(Customer::factory()->broker()->create());
        $theirs = $this->post('/api/v1/media', ['photo' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->json('data.id');

        $this->signIn($broker);
        $this->postJson('/api/v1/listings', $this->form(['media_ids' => [$first, $theirs]]))->assertStatus(422);
        $id = $this->postJson('/api/v1/listings', $this->form(['media_ids' => [$first, $second], 'cover_id' => $second]))->assertCreated()->json('data.id');

        $listing = Listing::query()->findOrFail($id);
        $this->assertSame([$second, $first], $listing->media->pluck('id')->all());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $listing->image_hash);
    }
}
