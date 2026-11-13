<?php

namespace Tests\Feature\Api;

use App\Enums\BoostKind;
use App\Jobs\NotifyAreaBrokers;
use App\Models\BoostUsage;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Package;
use App\Models\SearchRequest;
use App\Models\Subscription;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * «Idempotency-Key» (App\Http\Middleware\Idempotent): a request sent again after its answer was lost — or twice at
 * once — is published once, notifies once, and spends the plan's «عاجل» once.
 */
class IdempotencyTest extends TestCase
{
    private Customer $author;

    private Customer $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = Customer::factory()->broker()->create(['city' => 'الرياض']);   // notified of requests in his city
        $this->author = $this->signIn(Customer::factory()->broker()->create(['city' => 'الرياض']));
        Subscription::query()->create(['customer_id' => $this->author->id, 'package_id' => Package::query()->where('code', 'broker-year')->value('id'),
            'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return ['purpose' => 'sale', 'city' => 'الرياض', 'neighborhoods' => ['الملقا'], 'property_types' => ['apartment'],
            'budget_min' => 700000, 'budget_max' => 950000, 'description' => 'شقة عوائل', 'urgent' => true];
    }

    /**
     * @param  array<string, mixed>|null  $form
     * @return TestResponse<Response>
     */
    private function send(string $key, ?array $form = null): TestResponse
    {
        return $this->postJson('/api/v1/requests', $form ?? $this->form(), ['Idempotency-Key' => $key]);
    }

    private function urgentSpent(): int
    {
        return BoostUsage::query()->where('customer_id', $this->author->id)->where('kind', BoostKind::UrgentRequest)->count();
    }

    public function test_sent_again_it_is_published_once_notifies_once_and_spends_urgent_once(): void
    {
        $first = $this->send('req-0001-abcdef')->assertCreated()->assertJsonPath('meta.urgent.status', 'applied');
        $again = $this->send('req-0001-abcdef')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $again->json(), 'the same answer');
        $this->assertSame(1, SearchRequest::query()->where('customer_id', $this->author->id)->count());
        $this->assertSame(1, $this->broker->notifications()->count());
        $this->assertSame(1, $this->urgentSpent());

        $this->send('req-0002-abcdef')->assertCreated();                // another operation: another request
        $this->assertSame(2, SearchRequest::query()->where('customer_id', $this->author->id)->count());
    }

    public function test_sent_while_the_first_still_runs_it_waits_and_runs_nothing(): void
    {
        // the first of two taps at once has taken the key (the unique row) and has not answered yet
        IdempotencyKey::query()->create(['customer_id' => $this->author->id, 'key' => 'req-0003-abcdef',
            'fingerprint' => hash('sha256', 'POST api/v1/requests '.json_encode($this->form()))]);

        $this->send('req-0003-abcdef')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');
        $this->assertSame(0, SearchRequest::query()->count());
        $this->assertSame(0, $this->broker->notifications()->count());
        $this->assertSame(0, $this->urgentSpent());
    }

    public function test_one_key_is_one_operation(): void
    {
        $this->send('req-0004-abcdef')->assertCreated();
        $this->send('req-0004-abcdef', ['description' => 'غيره'] + $this->form())->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_MISMATCH');

        $other = $this->signIn(Customer::factory()->owner()->create());
        $this->send('req-0004-abcdef')->assertCreated();                  // a key is the account's own
        $this->assertSame(1, SearchRequest::query()->where('customer_id', $other->id)->count());
    }

    public function test_a_refusal_is_replayed_and_a_key_that_is_not_one_is_refused(): void
    {
        $this->send('req-0005-abcdef', ['neighborhoods' => ['حي لا يوجد']] + $this->form())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->send('req-0005-abcdef', ['neighborhoods' => ['حي لا يوجد']] + $this->form())->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');

        $this->send('short')->assertStatus(422);
        $this->postJson('/api/v1/requests', $this->form())->assertCreated();   // without the key: as before
        $this->postJson('/api/v1/requests', $this->form())->assertCreated();
        $this->assertSame(2, SearchRequest::query()->count());
    }

    public function test_kept_a_day(): void
    {
        $this->send('req-0006-abcdef')->assertCreated();
        $this->travel(25)->hours();
        $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    public function test_a_retry_after_the_notice_could_not_be_queued_publishes_and_charges_nothing_more(): void
    {
        $real = app(Dispatcher::class);
        $failOnce = true;
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function (object $job) use ($real, &$failOnce) {
            if ($failOnce && $job instanceof NotifyAreaBrokers) {
                $failOnce = false;
                throw new RuntimeException('queue unavailable');
            }

            return $real->dispatch($job);
        });
        $bus->shouldIgnoreMissing($real);
        $this->app->instance(Dispatcher::class, $bus);
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported) {
            $reported[] = $e->getMessage();
        });

        $first = $this->send('req-after-save-0001')->assertCreated()->assertJsonPath('meta.urgent.status', 'applied');
        $this->send('req-after-save-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), SearchRequest::query()->where('customer_id', $this->author->id)->sole()->id);
        $this->assertSame(1, $this->urgentSpent());
        $this->assertSame(['queue unavailable'], $reported, 'the lost notice is reported, not hidden');
    }

    public function test_when_the_charge_fails_after_the_request_is_saved_nothing_stays_and_the_retry_runs_once(): void
    {
        $failOnce = true;
        BoostUsage::creating(function () use (&$failOnce) {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('database went away');
            }
        });

        $this->send('req-charge-fails-0001')->assertStatus(500);
        $this->assertSame(0, SearchRequest::query()->count(), 'the request went with its charge');
        $this->assertSame(0, $this->urgentSpent());

        $this->send('req-charge-fails-0001')->assertCreated()->assertJsonPath('meta.urgent.status', 'applied');
        $this->assertSame(1, SearchRequest::query()->count());
        $this->assertSame(1, $this->urgentSpent());
    }
}
