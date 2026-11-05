<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\Monitoring;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

use function Sentry\captureException;

/**
 * What an error sends to Sentry (config/sentry.php, App\Support\Monitoring): the trace id, the app's platform and
 * version, the account as a bare id — and none of the person's data, whatever the request carried.
 *
 * The application boots with a DSN, as in production (Sentry registers its request middleware and its features
 * only then); the transport keeps the events here instead of sending them.
 */
class MonitoringTest extends TestCase
{
    private const DSN = 'https://public@o1.ingest.sentry.invalid/1';

    /** @var list<Event> what would have left for Sentry */
    private array $sent = [];

    public function createApplication(): Application
    {
        $before = [$_ENV['SENTRY_LARAVEL_DSN'] ?? null, $_SERVER['SENTRY_LARAVEL_DSN'] ?? null, getenv('SENTRY_LARAVEL_DSN')];
        $_ENV['SENTRY_LARAVEL_DSN'] = $_SERVER['SENTRY_LARAVEL_DSN'] = self::DSN;
        putenv('SENTRY_LARAVEL_DSN='.self::DSN);
        try {
            return parent::createApplication();
        } finally {
            [$env, $server, $put] = $before;
            $_ENV['SENTRY_LARAVEL_DSN'] = $env;
            if ($server === null) {
                unset($_SERVER['SENTRY_LARAVEL_DSN']);
            } else {
                $_SERVER['SENTRY_LARAVEL_DSN'] = $server;
            }
            putenv('SENTRY_LARAVEL_DSN='.($put === false ? '' : $put));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $transport = new class($this->sent) implements TransportInterface
        {
            /** @param list<Event> $sent */
            public function __construct(private array &$sent) {}

            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        $this->app->extend(ClientBuilder::class, fn (ClientBuilder $builder) => $builder->setTransport($transport));
        $this->app->forgetInstance(HubInterface::class);
        SentrySdk::setCurrentHub($this->app->make(HubInterface::class));
    }

    protected function tearDown(): void
    {
        SentrySdk::init();   // the next test classes boot without a DSN and must not inherit this client
        parent::tearDown();
    }

    public function test_an_unexpected_error_carries_the_trace_platform_and_account_but_nothing_of_the_person(): void
    {
        Route::middleware(['api', 'auth:api'])->post('api/v1/_boom', fn () => throw new RuntimeException('boom'));
        $customer = Customer::factory()->seeker()->create(['name' => 'فيصل العتيبي', 'phone' => '0551234567']);
        $token = $customer->createToken('iPhone 16')->plainTextToken;

        $this->withToken($token)->withHeaders([
            'X-Request-Id' => 'trace-from-the-app-01',
            'X-App-Platform' => 'ios',
            'X-App-Version' => '1.2.1',
            'X-Device-Id' => 'device-0551234567',
        ])->withUnencryptedCookie('zon_session', 'secret-cookie')
            ->postJson('/api/v1/_boom?phone=0551234567', ['name' => 'فيصل العتيبي', 'phone' => '0551234567', 'notes' => 'أبحث عن فيلا'])
            ->assertStatus(500)->assertJsonPath('error.trace_id', 'trace-from-the-app-01');

        $this->assertCount(1, $this->sent);
        $event = $this->sent[0];
        $this->assertSame('boom', $event->getExceptions()[0]->getValue());
        $this->assertSame('trace-from-the-app-01', $event->getTags()[Monitoring::TRACE_TAG]);
        $this->assertSame(['ios', '1.2.1'], [$event->getTags()['app.platform'], $event->getTags()['app.version']]);
        $this->assertSame('customer:'.$customer->id, $event->getUser()?->getId());
        $this->assertNull($event->getUser()->getIpAddress());
        $this->assertNull($event->getUser()->getEmail());
        $this->assertNull($event->getUser()->getUsername());

        $request = $event->getRequest();
        $this->assertStringEndsWith('/api/v1/_boom', (string) ($request['url'] ?? ''), 'the route is known');
        $this->assertArrayNotHasKey('data', $request, 'no request body');
        $this->assertEmpty($request['cookies'] ?? null, 'no cookies');
        $this->assertEmpty($request['query_string'] ?? null, 'no query string');
        $headers = array_change_key_case($request['headers'] ?? []);
        $this->assertSame(['ios'], (array) $headers['x-app-platform']);
        $this->assertSame(['trace-from-the-app-01'], (array) $headers['x-request-id']);
        $this->assertArrayNotHasKey('cookie', $headers);

        $everything = json_encode([$request, $event->getTags(), $event->getExtra(), $event->getContexts(),
            array_map(fn (Breadcrumb $b) => [$b->getMessage(), $b->getMetadata()], $event->getBreadcrumbs())], JSON_UNESCAPED_UNICODE);
        foreach (['0551234567', 'فيصل', 'أبحث عن فيلا', 'secret-cookie', $token, explode('|', $token)[1]] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $everything, "«{$personal}» left for Sentry");
        }
    }

    public function test_a_database_error_names_the_statement_and_the_key_but_not_the_value(): void
    {
        Customer::factory()->seeker()->create(['username' => 'faisal.otaibi']);

        try {
            Customer::factory()->seeker()->create(['username' => 'faisal.otaibi']);
            $this->fail('the second account with the same username should not be saved');
        } catch (QueryException $e) {
            captureException($e);
        }

        $this->assertCount(1, $this->sent);
        $values = implode("\n", array_map(fn ($exception) => $exception->getValue(), $this->sent[0]->getExceptions()));
        $this->assertStringNotContainsString('faisal.otaibi', $values);
        $this->assertStringContainsString("Duplicate entry '?'", $values);
        $this->assertStringContainsString('customers_username_unique', $values, 'the key stays: it says which rule broke');
        $this->assertStringContainsString('values (?', $values, 'the statement keeps its placeholders');
    }

    public function test_the_sms_simulators_code_stays_out_of_the_breadcrumbs(): void
    {
        $this->assertNull(Monitoring::beforeBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'sms.simulated')));
        $kept = new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log', 'push.failed');
        $this->assertSame($kept, Monitoring::beforeBreadcrumb($kept));
    }

    public function test_an_expected_answer_is_not_an_error(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/listings/999999')->assertNotFound();
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();

        $this->assertSame([], $this->sent);
    }
}
