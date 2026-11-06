<?php

namespace Tests\Feature\Integrations;

use App\Enums\NoteKind;
use App\Integrations\IntegrationException;
use App\Integrations\Push\PushGateway;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Notifications\AppNotice;
use Tests\TestCase;

/**
 * A notice on its way to the phones (App\Notifications\PushChannel): every phone of the account gets it with its
 * kind and target, a phone the provider says is gone is forgotten, and a provider failure costs nothing but the push.
 */
class PushChannelTest extends TestCase
{
    private function notice(): AppNotice
    {
        return new AppNotice(NoteKind::Match, 'عروض تطابق رغبتك', 'عرضان جديدان يطابقان ما تبحث عنه.', '/account/matching');
    }

    private function phone(Customer $customer, string $token): void
    {
        CustomerDevice::query()->create(['customer_id' => $customer->id, 'platform' => 'ios', 'push_token' => $token]);
    }

    /** @param list<string>|IntegrationException $answer a provider that answers every send with the dead ids, or an exception */
    private function provider(array|IntegrationException $answer): void
    {
        $this->app->instance(PushGateway::class, new class($answer) implements PushGateway
        {
            /** @param list<string>|IntegrationException $answer */
            public function __construct(private readonly array|IntegrationException $answer) {}

            public function send(array $tokens, string $title, string $body, array $data = []): array
            {
                return $this->answer instanceof IntegrationException ? throw $this->answer : $this->answer;
            }
        });
    }

    public function test_a_notice_reaches_every_phone_of_the_account_with_its_kind_and_target(): void
    {
        $seeker = Customer::factory()->seeker()->create();
        $this->phone($seeker, 'fid-iphone');
        $this->phone($seeker, 'fid-ipad');
        $this->phone(Customer::factory()->seeker()->create(), 'fid-someone-else');

        $seeker->notify($this->notice());

        $sent = $this->push()->sent();
        $this->assertCount(1, $sent, 'one message for the account, to all its phones');
        $this->assertEqualsCanonicalizing(['fid-iphone', 'fid-ipad'], $sent[0]['tokens']);
        $this->assertSame(['عروض تطابق رغبتك', 'match', '/account/matching'], [$sent[0]['title'], $sent[0]['data']['kind'], $sent[0]['data']['target']]);
        $this->assertSame(1, $seeker->notifications()->count(), 'and the bell');
    }

    public function test_a_phone_the_provider_says_is_gone_is_forgotten(): void
    {
        $seeker = Customer::factory()->seeker()->create();
        $this->phone($seeker, 'fid-live');
        $this->phone($seeker, 'fid-gone');
        $this->provider(['fid-gone']);

        $seeker->notify($this->notice());

        $this->assertSame(['fid-live'], $seeker->devices()->pluck('push_token')->all());
    }

    public function test_a_provider_failure_keeps_the_bell_and_every_phone(): void
    {
        $seeker = Customer::factory()->seeker()->create();
        $this->phone($seeker, 'fid-live');
        $this->provider(new IntegrationException('push', 'HTTP 500: INTERNAL'));

        $seeker->notify($this->notice());

        $this->assertSame(1, $seeker->notifications()->count());
        $this->assertSame(['fid-live'], $seeker->devices()->pluck('push_token')->all(), 'a failure is not a dead phone');
    }
}
