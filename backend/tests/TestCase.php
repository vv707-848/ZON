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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every test runs on MySQL (zon_test) with the base seed (cities, plans, roles, knowledge), and no request may
 * leave the machine: Http::preventStrayRequests() — a test that needs the agents service fakes agents.test.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @var array{array<string, mixed>, int} what the agents service answers to index signals (contract §4) unless a test changes it: the body and the status */
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
        $dir = (string) getenv('ZON_CONTRACT_DIR');   // the shell's variable, not the application's config
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
                self::$samples[$key][md5($body)] = json_decode($body);   // objects, not arrays: an empty {} stays {}
            }
            File::ensureDirectoryExists(dirname("{$dir}/{$key}.json"));
            File::put("{$dir}/{$key}.json", json_encode(array_values(self::$samples[$key] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        });
    }

    /**
     * The app's contract with the API (docs/contracts/fixtures/api/<name>.json): a real response, which the app's
     * tests replay through its own parsers (app/test/server). This response must have the recorded shape — every
     * key at every level (a value may be null on one side) — so a key renamed or dropped here fails on the server,
     * not in the app in production. Recorded again only by hand, on purpose (the app's tests then run on the new
     * answers): ZON_RECORD_API=1 php artisan test. Never in CI, which compares; ZON_CONTRACT_DIR, which CI sets to
     * record what is sent to the agents, has nothing to do with it.
     *
     * @param  TestResponse<covariant Response>  $response
     */
    protected function apiContract(string $name, TestResponse $response): void
    {
        $path = base_path("../docs/contracts/fixtures/api/{$name}.json");
        $live = $response->json();
        if (getenv('ZON_RECORD_API') === '1') {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            return;
        }
        $this->assertFileExists($path, "No recorded contract {$name}: ZON_RECORD_API=1 php artisan test");
        $recorded = json_decode((string) File::get($path), true);
        $difference = self::difference($recorded, $live, $name);
        $this->assertNull($difference, "The API's {$name} response is no longer what the app was tested with (docs/contracts/fixtures/api/{$name}.json): {$difference}");
    }

    /** Where two JSON values differ in shape: keys at every level, a list by its first item; null matches anything. */
    private static function difference(mixed $recorded, mixed $live, string $at): ?string
    {
        if ($recorded === null || $live === null) {
            return null;
        }
        if (! is_array($recorded) || ! is_array($live)) {
            return is_array($recorded) === is_array($live) ? null : "{$at} changed between a value and a list or object";
        }
        if (array_is_list($recorded) && array_is_list($live)) {
            return $recorded === [] || $live === [] ? null : self::difference($recorded[0], $live[0], "{$at}[0]");
        }
        $missing = array_diff(array_keys($recorded), array_keys($live));
        $added = array_diff(array_keys($live), array_keys($recorded));
        if ($missing !== [] || $added !== []) {
            return "{$at}: ".trim(($missing === [] ? '' : 'gone '.implode(', ', $missing)).' '.($added === [] ? '' : 'new '.implode(', ', $added)));
        }
        foreach ($recorded as $key => $value) {
            if (($found = self::difference($value, $live[$key], "{$at}.{$key}")) !== null) {
                return $found;
            }
        }

        return null;
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
