<?php

namespace Tests\Feature\Api;

use App\Enums\CustomerRole;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\PublishingGate;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Nafath once per account, before the first publication (on the simulated Nafath until the contract): a request
 * waits for the person's choice in the Nafath app, then completes or is rejected — or expires and may start again.
 */
class NafathTest extends TestCase
{
    private function broker(): Customer
    {
        return $this->signIn(Customer::factory()->broker()->withoutNafath()->create());
    }

    public function test_a_request_waits_then_verifies_and_opens_publishing(): void
    {
        $broker = $this->broker();
        try {
            PublishingGate::assert($broker, CustomerRole::Broker);
            $this->fail('publishing needs Nafath first');
        } catch (ApiException $e) {
            $this->assertSame('NAFATH_REQUIRED', $e->error->value);
        }

        $started = $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->assertOk()->json('data');
        $this->assertSame($started, $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->json('data'), 'one request at a time');

        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.attempt', 'waiting')->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.random_number', $started['random_number']);
        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.attempt', 'verified')->assertJsonPath('data.status', 'verified');
        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.status', 'verified')->assertJsonPath('data.attempt', null);

        PublishingGate::assert($broker->fresh(), CustomerRole::Broker);
        $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ACTIVE');
        $this->assertStringNotContainsString('1012345678', (string) Activity::query()->where('event', 'nafath')->sole()->properties, 'the id is kept nowhere');
    }

    public function test_a_rejection_is_kept_and_a_new_request_may_follow(): void
    {
        $this->broker();

        $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345670'])->assertOk();
        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.attempt', 'waiting');
        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.attempt', 'rejected')->assertJsonPath('data.status', 'rejected');

        $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->assertOk();
        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.status', 'pending');
    }

    public function test_an_unanswered_request_expires_and_the_account_may_start_again(): void
    {
        $broker = $this->broker();
        $first = $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->json('data.transaction_id');

        $this->travel(181)->seconds();

        $this->getJson('/api/v1/me/nafath')->assertJsonPath('data.attempt', 'expired')->assertJsonPath('data.status', 'none');
        $this->assertSame('none', $broker->fresh()->nafath_status->value);
        $this->assertNotSame($first, $this->postJson('/api/v1/me/nafath', ['national_id' => '1012345678'])->json('data.transaction_id'));
    }
}
