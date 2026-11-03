<?php

namespace Tests\Feature\Api;

use App\Events\MessageSent;
use App\Jobs\NotifyAreaBrokers;
use App\Jobs\NotifyFollowers;
use App\Jobs\Scheduled\ComputeDailyMetrics;
use App\Jobs\Scheduled\NotifyWishMatches;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\DailyMetric;
use App\Models\Follow;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Project;
use App\Models\SearchFeedback;
use App\Models\SearchMiss;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Models\TrainingSample;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A review account lives in a sandbox (App\Support\Sandbox): it sees the platform as everyone does, and nothing it
 * makes or does reaches a real user, a guest, the numbers or the AI's training samples. Testers see one another.
 */
class SandboxTest extends TestCase
{
    /** @return list<int> */
    private function ids(TestResponse $response, string $key = 'id'): array
    {
        return array_column($response->assertOk()->json('data'), $key);
    }

    public function test_what_a_tester_publishes_reaches_neither_a_real_user_nor_a_guest(): void
    {
        $testerBroker = Customer::factory()->broker()->tester()->create();
        $testerSeeker = Customer::factory()->seeker()->tester()->create();
        $hidden = Listing::factory()->published()->create(['customer_id' => $testerBroker->id]);
        $request = SearchRequest::factory()->create(['customer_id' => $testerSeeker->id]);
        $project = Project::factory()->published()->create(['developer_id' => Customer::factory()->developer()->tester()]);
        $real = Listing::factory()->published()->create();
        $realBroker = Customer::factory()->broker()->create();

        foreach ([null, $realBroker] as $viewer) {
            if ($viewer !== null) {
                $this->signIn($viewer);
            }
            $this->assertSame([$real->id], $this->ids($this->getJson('/api/v1/listings')));
            $this->assertSame([], $this->ids($this->getJson('/api/v1/requests')));
            $this->assertNotContains($project->id, $this->ids($this->getJson('/api/v1/projects')));
            $this->getJson("/api/v1/listings/{$hidden->id}")->assertNotFound();
            $this->getJson("/api/v1/requests/{$request->id}")->assertNotFound();
            $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
            $this->getJson("/api/v1/customers/{$testerBroker->username}")->assertNotFound();
        }
        $this->postJson('/api/v1/conversations', ['to' => $testerBroker->username, 'body' => 'مرحبا'])->assertNotFound();
        $this->postJson('/api/v1/favorites', ['type' => 'listing', 'id' => $hidden->id])->assertNotFound();
        $this->postJson('/api/v1/support-tickets', ['type' => 'complaint', 'listing_id' => $hidden->id, 'body' => 'عن عرض لا أراه'])->assertNotFound();
        $this->postJson("/api/v1/requests/{$request->id}/to-client")->assertNotFound();
        $this->assertSame(0, $hidden->fresh()->views_count + $real->fresh()->views_count, 'no view was counted on what was refused');

        $this->signIn($testerSeeker);                   // the sandbox sees the platform, and itself
        $this->assertEqualsCanonicalizing([$real->id, $hidden->id], $this->ids($this->getJson('/api/v1/listings')));
        $this->getJson("/api/v1/listings/{$real->id}")->assertOk();
        $this->assertSame(0, $real->fresh()->views_count, "a tester's look is not a view");
    }

    public function test_a_testers_messages_follows_stars_and_reports_stay_on_his_side(): void
    {
        Event::fake([MessageSent::class]);
        $broker = Customer::factory()->broker()->create();
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        foreach (Customer::factory()->owner()->count(2)->create() as $reporter) {      // two real reports of three needed
            $this->signIn($reporter);
            $this->postJson('/api/v1/reports', ['target_type' => 'listing', 'target_id' => $listing->id, 'details' => 'سعر غير صحيح'])->assertCreated();
        }
        $tester = $this->signIn(Customer::factory()->seeker()->tester()->create());
        BuyerPreference::factory()->create(['customer_id' => $tester->id]);     // a seeker acts with a live wish

        $conversation = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'تجربة'])
            ->assertCreated()->json('data.conversation_id');
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['body' => 'تجربة ثانية'])->assertCreated();
        $this->assertCount(2, $this->getJson("/api/v1/conversations/{$conversation}/messages")->assertOk()->json('data'), 'the tester has his chat');
        $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertOk();
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 1])->assertOk();
        $this->postJson('/api/v1/reports', ['target_type' => 'listing', 'target_id' => $listing->id, 'details' => 'تجربة بلاغ'])->assertCreated();

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->recipientIds === []);
        $this->assertSame(0, $broker->notifications()->count(), 'no message, star or anything else reached him');
        $this->assertSame('published', $listing->fresh()->status->value, "a tester's report never hides real content");

        $this->signIn($broker);
        $this->assertSame([], $this->getJson('/api/v1/conversations')->assertOk()->json('data'));
        $this->getJson("/api/v1/conversations/{$conversation}/messages")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/me/followers')->assertOk()->json('data'));
        $this->getJson("/api/v1/customers/{$broker->username}")->assertOk()
            ->assertJsonPath('data.followers_count', 0)->assertJsonPath('data.rating.count', 0);

        $this->signIn($tester);                         // inside the sandbox, his own follow and star are there
        $this->getJson("/api/v1/customers/{$broker->username}")->assertJsonPath('data.followers_count', 1)->assertJsonPath('data.rating.count', 1);
    }

    public function test_news_of_a_testers_listing_or_request_reaches_testers_only(): void
    {
        $testerBroker = Customer::factory()->broker()->tester()->create();
        [$realFollower, $testerFollower] = [Customer::factory()->create(), Customer::factory()->tester()->create()];
        Follow::query()->insert([['follower_id' => $realFollower->id, 'followed_id' => $testerBroker->id], ['follower_id' => $testerFollower->id, 'followed_id' => $testerBroker->id]]);
        $realWish = BuyerPreference::factory()->create(['created_at' => now()->subDay()]);
        $testerWish = BuyerPreference::factory()->create(['customer_id' => Customer::factory()->tester(), 'created_at' => now()->subDay()]);
        $listing = Listing::factory()->published()->create(['customer_id' => $testerBroker->id, 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'price' => 850000]);

        (new NotifyFollowers($listing->id))->handle();
        (new NotifyWishMatches)->handle();
        $this->assertSame([0, 1], [$realFollower->notifications()->count(), $testerFollower->notifications()->count()]);
        $this->assertSame([0, 1], [$realWish->customer->notifications()->count(), $testerWish->customer->notifications()->count()]);

        [$realBroker, $otherTester] = [Customer::factory()->broker()->create(), Customer::factory()->broker()->tester()->create()];
        $request = SearchRequest::factory()->create(['customer_id' => Customer::factory()->tester()]);
        (new NotifyAreaBrokers($request->id))->handle();
        $this->assertSame([0, 1], [$realBroker->notifications()->count(), $otherTester->notifications()->count()]);
    }

    public function test_a_tester_counts_nowhere(): void
    {
        $seekerPlan = Package::query()->where('code', 'seeker')->value('id');
        $search = ['city' => 'الرياض', 'neighborhood' => 'الياسمين', 'property_type' => 'villa', 'query' => 'فيلا بمسبح'];
        $feedback = ['context' => 'search', 'result_type' => 'listing', 'result_id' => 1, 'is_match' => false, 'reason' => 'بعيد عن المدارس'];
        foreach ([Customer::factory()->seeker()->tester()->create(), Customer::factory()->seeker()->create()] as $i => $seeker) {
            BuyerPreference::factory()->create(['customer_id' => $seeker->id]);
            Subscription::query()->create(['customer_id' => $seeker->id, 'package_id' => $seekerPlan, 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
            $this->signIn($seeker);
            $this->postJson('/api/v1/ai/search', $search, ['X-Device-Id' => "device-{$i}"])->assertOk()->assertJsonPath('meta.ai.status', 'skipped');
            $this->postJson('/api/v1/ai/feedback', $feedback)->assertCreated();
        }

        $this->assertSame([1, 1, 2], [SearchMiss::query()->count(), SearchFeedback::query()->count(), TrainingSample::query()->count()], "the real seeker's miss, mark and two samples only");
        Listing::factory()->published()->create(['customer_id' => Customer::factory()->broker()->tester()]);
        $this->assertSame([1, 0, 1], [DB::table('ai_v_customers')->count(), DB::table('ai_v_listings')->count(), DB::table('ai_v_preferences')->count()]);

        $this->travel(1)->day();
        (new ComputeDailyMetrics)->handle();
        $this->assertSame(1.0, (float) DailyMetric::query()->where('metric', 'signups')->sum('value'), 'the testers did not sign up anyone');
        $this->assertSame(0.0, (float) DailyMetric::query()->where('metric', 'listings.published')->value('value'));
    }
}
