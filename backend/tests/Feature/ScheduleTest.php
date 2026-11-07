<?php

namespace Tests\Feature;

use App\Actions\Accounts\CancelDeletion;
use App\Actions\Accounts\PurgeAccount;
use App\Actions\Accounts\RequestDeletion;
use App\Actions\Moderation\ModerateContent;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Jobs\Scheduled\AutoPublishPendingReviews;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Jobs\Scheduled\ComputeDailyMetrics;
use App\Jobs\Scheduled\EndBoosts;
use App\Jobs\Scheduled\InactivateStaleListings;
use App\Jobs\Scheduled\NotifyWishMatches;
use App\Jobs\Scheduled\PurgeDueAccounts;
use App\Jobs\Scheduled\RemindPendingReviews;
use App\Jobs\Scheduled\ResumePausedListings;
use App\Jobs\Scheduled\RetryPendingIndexEvents;
use App\Jobs\Scheduled\SendTaskReminders;
use App\Models\BoostUsage;
use App\Models\BuyerPreference;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\DailyAreaStat;
use App\Models\DailyMetric;
use App\Models\DeletionRequest;
use App\Models\IndexEvent;
use App\Models\Listing;
use App\Models\ListingHistory;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use App\Models\Task;
use App\Models\User;
use App\Support\PublicView;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** The schedule's jobs: each does its work once, and running it again changes nothing. */
class ScheduleTest extends TestCase
{
    private function pendingReview(int $hoursAgo): Listing
    {
        $listing = Listing::factory()->licensed()->create(['status' => ListingStatus::PendingReview, 'text_review' => TextReview::Ready, 'desc_suggested' => 'مقترح']);
        ListingHistory::query()->create(['listing_id' => $listing->id, 'field' => 'status', 'old_value' => 'indexing', 'new_value' => 'pending_review', 'actor' => 'system', 'changed_at' => now()->subHours($hoursAgo)]);

        return $listing;
    }

    public function test_the_wording_deadline_reminds_once_then_publishes_as_written(): void
    {
        $reminded = $this->pendingReview(30);
        $overdue = $this->pendingReview(73);
        $fresh = $this->pendingReview(2);

        (new RemindPendingReviews)->handle();
        (new RemindPendingReviews)->handle();
        $this->assertSame(1, $reminded->customer->notifications()->where('data->key', "wording-reminder:{$reminded->id}")->count());
        $this->assertSame(0, $fresh->customer->notifications()->count());

        (new AutoPublishPendingReviews)->handle();
        $this->assertSame(['published', 'auto'], [$overdue->fresh()->status->value, $overdue->fresh()->text_review->value]);
        $this->assertSame('مقترح', $overdue->fresh()->desc_suggested, 'the review button stays');
        $this->assertSame('pending_review', $reminded->fresh()->status->value);
    }

    public function test_nothing_of_an_account_being_deleted_is_published_and_a_cancellation_publishes_it(): void
    {
        $listing = $this->pendingReview(73);                 // its wording deadline passed: the job would publish it
        $deletion = app(RequestDeletion::class)($listing->customer);
        $request = SearchRequest::factory()->create(['customer_id' => $listing->customer_id]);   // hidden by the deletion

        (new AutoPublishPendingReviews)->handle();
        $this->actingAs(User::factory()->superadmin()->create(), 'web');   // a moderator restores the request
        app(ModerateContent::class)->request($request->fresh(), RequestStatus::Published, 'بلاغ غير صحيح');

        $this->assertSame([ListingStatus::Hidden, RequestStatus::Hidden], [$listing->fresh()->status, $request->fresh()->status]);
        $this->assertFalse(PublicView::listing($listing->fresh(), null));
        $this->assertSame(['listings' => [$listing->id => 'published'], 'requests' => [$request->id => 'published']],
            array_filter(array_intersect_key($deletion->fresh()->hidden_items, array_flip(['listings', 'requests']))));

        app(CancelDeletion::class)($deletion->fresh(), 'owner');

        $this->assertSame([ListingStatus::Published, RequestStatus::Published], [$listing->fresh()->status, $request->fresh()->status]);
    }

    public function test_pauses_end_and_stale_listings_become_inactive(): void
    {
        $paused = Listing::factory()->published()->create();
        $paused->forceFill(['status' => ListingStatus::Paused, 'paused_until' => now()->subMinute()])->save();
        $stale = Listing::factory()->published()->create(['last_activity_at' => now()->subDays(15)]);

        (new ResumePausedListings)->handle();
        (new InactivateStaleListings)->handle();

        $this->assertSame('published', $paused->fresh()->status->value);
        $this->assertSame('inactive', $stale->fresh()->status->value);
        $this->assertSame(1, $stale->customer->notifications()->count());
    }

    public function test_finished_boosts_are_told_once_and_an_urgent_request_returns_to_normal(): void
    {
        $listing = Listing::factory()->published()->create(['featured_until' => now()->subMinute()]);
        $request = SearchRequest::factory()->urgent()->create();
        foreach ([[$listing, 'featured'], [$request, 'urgent_request']] as [$target, $kind]) {
            BoostUsage::query()->create(['customer_id' => $target->customer_id, 'kind' => $kind, 'target_type' => $target->getMorphClass(), 'target_id' => $target->id,
                'via' => 'grant', 'starts_at' => now()->subWeek(), 'active_until' => now()->subMinute(), 'created_at' => now()->subWeek()]);
        }

        (new EndBoosts)->handle();
        (new EndBoosts)->handle();

        $this->assertFalse($request->fresh()->urgent);
        $this->assertSame(1, $listing->customer->notifications()->count());
    }

    public function test_task_reminders_and_wish_matches(): void
    {
        $task = Task::query()->create(['customer_id' => Customer::factory()->create()->id, 'title' => 'اتصال', 'remind_at' => now()->subMinute()]);
        (new SendTaskReminders)->handle();
        (new SendTaskReminders)->handle();
        $this->assertSame(1, $task->customer->notifications()->count());

        $wish = BuyerPreference::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_max' => 900000, 'created_at' => now()->subDay()]);
        Listing::factory()->published()->create(['price' => 850000, 'published_at' => now()]);
        Listing::factory()->published()->create(['price' => 1500000, 'published_at' => now()]);    // over budget

        (new NotifyWishMatches)->handle();
        $this->assertSame('1 عرض جديد يطابق ما تبحث عنه.', $wish->customer->notifications()->sole()->data['body']);
        $this->travel(1)->minute();
        (new NotifyWishMatches)->handle();
        $this->assertSame(1, $wish->customer->notifications()->count(), 'nothing new since the last run');
    }

    public function test_daily_statistics_spread_requests_and_take_medians(): void
    {
        $yesterday = now('Asia/Riyadh')->subDay()->setTime(12, 0);
        foreach ([1000000, 2000000, 4000000] as $budget) {
            SearchRequest::factory()->create(['neighborhoods' => ['النرجس', 'الملقا'], 'property_types' => ['villa'], 'budget_max' => $budget, 'created_at' => $yesterday]);
        }
        Listing::factory()->published()->create(['property_type' => 'villa', 'price' => 1800000]);
        Listing::factory()->published()->create(['property_type' => 'villa', 'price' => 2200000]);

        (new ComputeDailyAreaStats)->handle();
        (new ComputeDailyMetrics)->handle();
        (new ComputeDailyMetrics)->handle();                    // a re-run replaces the day

        $narjis = DailyAreaStat::query()->where('neighborhood', 'النرجس')->where('property_type', 'villa')->sole();
        $this->assertSame([3, 2, '2000000.00', '2000000.00'], [$narjis->requests_count, $narjis->listings_count, $narjis->median_budget, $narjis->median_price]);
        $this->assertSame(3, DailyAreaStat::query()->where('neighborhood', 'الملقا')->value('requests_count'));
        $this->assertSame('3.00', DailyMetric::query()->where('metric', 'requests.created')->sole()->value);
    }

    public function test_an_account_is_purged_after_its_grace_period_and_its_desk_is_gone(): void
    {
        $broker = Customer::factory()->broker()->create();
        CrmClient::query()->create(['broker_id' => $broker->id, 'name' => 'عميل', 'city' => 'الرياض']);
        app(RequestDeletion::class)($broker);
        $this->travel(31)->days();

        (new PurgeDueAccounts)->handle();

        $broker->refresh();
        $this->assertSame(['حساب محذوف', "deleted-{$broker->id}"], [$broker->name, $broker->username]);
        $this->assertNotNull($broker->anonymized_at);
        $this->assertSame(0, CrmClient::query()->count());
        $this->postJson('/api/v1/auth/login', ['login' => "deleted-{$broker->id}", 'password' => 'password'])->assertStatus(401);
    }

    public function test_the_record_a_purged_account_leaves_neither_reaches_nor_names_him(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'فهد القحطاني']);
        $listing = Listing::factory()->published()->create([
            'customer_id' => $broker->id,
            'title' => str_repeat('ب', 156).' فهد',                  // 160, the column: «العميل» is longer than «فهد»
            'desc_raw' => 'فيلا للبيع، تواصل مع فهد على 0551234567 أو fahad@example.com',
            'desc_clean' => 'فيلا للبيع، للتواصل: 055 123 4567',
            'phones' => ['0551234567'],
        ]);
        $removed = Listing::factory()->create(['customer_id' => $broker->id, 'desc_raw' => 'اتصل على 0559876543']);
        $removed->delete();
        $request = SearchRequest::factory()->create(['customer_id' => $broker->id, 'description' => 'شقة قريبة، القحطاني 0551234567']);
        $developer = Customer::factory()->developer()->create(['name' => 'نورة الشمري']);
        $project = Project::factory()->published()->create(['developer_id' => $developer->id, 'desc_raw' => 'مشروع نورة: 0501112223', 'phones' => ['0501112223']]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'model_name' => 'نموذج الشمري', 'desc_raw' => 'للحجز 0501112223']);
        app(RequestDeletion::class)($broker);
        app(RequestDeletion::class)($developer);
        $this->travel(31)->days();

        (new PurgeDueAccounts)->handle();

        $kept = json_encode([
            Listing::withTrashed()->whereKey([$listing->id, $removed->id])->get(['title', 'desc_raw', 'desc_suggested', 'desc_clean', 'phones']),
            SearchRequest::query()->whereKey($request->id)->get(['description']),
            Project::withTrashed()->whereKey($project->id)->get(['title', 'desc_raw', 'desc_suggested', 'desc_clean', 'phones']),
            ProjectUnit::query()->whereKey($unit->id)->get(['model_name', 'desc_raw', 'desc_suggested', 'desc_clean']),
        ], JSON_UNESCAPED_UNICODE);
        foreach (['0551234567', '123 4567', '0559876543', 'fahad@example.com', 'فهد', 'القحطاني', '0501112223', 'نورة', 'الشمري'] as $trace) {
            $this->assertStringNotContainsString($trace, $kept);
        }
        foreach (['فيلا للبيع', 'شقة قريبة', 'مشروع العميل', 'نموذج العميل', 'للحجز [رقم]'] as $record) {
            $this->assertStringContainsString($record, $kept);                         // the record itself stays
        }
        $this->assertNull($listing->fresh()->phones);
        $this->assertSame(160, mb_strlen((string) $listing->fresh()->title));
    }

    public function test_one_account_that_fails_to_purge_holds_back_no_other(): void
    {
        $first = Customer::factory()->broker()->create();
        $second = Customer::factory()->broker()->create();
        app(RequestDeletion::class)($first);
        app(RequestDeletion::class)($second);
        $this->travel(31)->days();
        $real = app(PurgeAccount::class);
        $this->app->instance(PurgeAccount::class, new class($real, $first->id)
        {
            public function __construct(private PurgeAccount $real, private int $failing) {}

            public function __invoke(DeletionRequest $deletion): void
            {
                throw_if($deletion->customer_id === $this->failing, new RuntimeException('down'));
                ($this->real)($deletion);
            }
        });

        (new PurgeDueAccounts)->handle();

        $this->assertNull($first->fresh()->anonymized_at);
        $this->assertNotNull($second->fresh()->anonymized_at);
    }

    public function test_unacknowledged_index_signals_are_sent_again(): void
    {
        $this->indexAnswer = [['detail' => 'qdrant down'], 503];
        $listing = Listing::factory()->published()->create();
        app(IndexSync::class)->listing($listing);
        $event = IndexEvent::query()->sole();
        $this->assertNull($event->acked_at);
        $this->assertStringContainsString('503', (string) $event->last_error);

        $this->indexAnswer = [['status' => 'indexed'], 200];
        $this->travel(20)->minutes();
        (new RetryPendingIndexEvents)->handle();
        $this->assertNotNull($event->fresh()->acked_at);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Signature') && str_starts_with($r->header('X-Signature')[0], 'sha256='));
    }
}
