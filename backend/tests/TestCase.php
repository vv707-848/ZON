<?php

namespace Tests;

use App\Integrations\Push\FakePushGateway;
use App\Integrations\Push\PushGateway;
use App\Integrations\Sms\FakeSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Every test runs on MySQL (zon_test) with the base seed (cities, plans, roles, knowledge), and no request may
 * leave the machine: Http::preventStrayRequests() — a test that needs the agents service fakes agents.test.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** What the agents service answers to index signals (contract §4) unless a test changes it. */
    protected array $indexAnswer = [['status' => 'indexed'], 200];

    /** @var array<string, array<string, mixed>> contract samples of this run: "agents/chat" => [hash => payload] */
    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_starts_with($request->url(), 'http://agents.test/index/')
            ? Http::response(...$this->indexAnswer) : null);
        Storage::fake('minio');
        Redis::connection('quota')->flushdb();      // REDIS_QUOTA_DB=15 in phpunit.xml
        $this->recordContractSamples();
    }

    /**
     * ZON_CONTRACT_DIR=../docs/contracts/fixtures php artisan test — keeps up to five distinct payloads of every
     * agent and index entity this suite sends; agents/tests/test_backend_contract.py checks them against the
     * agents service's own input models (a renamed or missing key fails there, not in production).
     */
    private function recordContractSamples(): void
    {
        $dir = (string) env('ZON_CONTRACT_DIR', '');
        if ($dir === '') {
            return;
        }
        Event::listen(RequestSending::class, function (RequestSending $event) use ($dir) {
            if ($event->request->method() !== 'POST' || ! preg_match('#^http://agents\.test/(agents|index)/([a-z_]+)$#', $event->request->url(), $m)) {
                return;
            }
            if (self::$samples === []) {
                File::deleteDirectory($dir);                // a run replaces the samples of the previous one
            }
            $key = "{$m[1]}/{$m[2]}";
            $body = $event->request->body();
            if (count(self::$samples[$key] ?? []) < 5) {
                self::$samples[$key][md5($body)] = json_decode($body, true);
            }
            File::ensureDirectoryExists(dirname("{$dir}/{$key}.json"));
            File::put("{$dir}/{$key}.json", json_encode(array_values(self::$samples[$key] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        });
    }

    protected function signIn(?Customer $customer = null): Customer
    {
        $customer ??= Customer::factory()->create();
        Sanctum::actingAs($customer, ['*'], 'api');

        return $customer;
    }

    protected function staff(bool $superadmin = false): User
    {
        $user = $superadmin ? User::factory()->superadmin()->create() : User::factory()->create();
        $this->actingAs($user, 'web');

        return $user;
    }

    protected function sms(): FakeSmsGateway
    {
        $sms = app(SmsGateway::class);
        assert($sms instanceof FakeSmsGateway);

        return $sms;
    }

    protected function push(): FakePushGateway
    {
        $push = app(PushGateway::class);
        assert($push instanceof FakePushGateway);

        return $push;
    }

    /** The code of the last SMS sent to a phone (fake driver). */
    protected function lastCode(string $phone): string
    {
        $messages = array_filter($this->sms()->sent(), fn (array $m) => $m['phone'] === $phone);
        preg_match('/\d{6}/', (string) end($messages)['text'], $m);

        return $m[0] ?? '';
    }
}
