<?php

namespace Tests\Feature\Admin;

use App\Enums\AiAgent;
use App\Enums\AiCallStatus;
use App\Enums\IndexAction;
use App\Enums\IndexEntity;
use App\Filament\Pages\HealthMonitor;
use App\Filament\Pages\TraceLookup;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\AiRequests\Pages\ListAiRequests;
use App\Jobs\Scheduled\SchedulerHeartbeat;
use App\Models\AiRequest;
use App\Models\IndexEvent;
use App\Support\Permissions\Permission;
use App\Support\Trace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

class HealthAndTraceTest extends AdminTestCase
{
    private bool $qdrantUp = true;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/agents/health')
            ? Http::response(['status' => 'ok', 'llm' => true, 'embed' => true, 'qdrant' => $this->qdrantUp, 'model' => 'qwen3.6-35b-a3b-fp8']) : null);
        (new SchedulerHeartbeat)->handle();
    }

    /** @return array<string, array{status: string, detail: string, hint: ?string}> the doctor's checks by key */
    private function doctor(int $exitCode): array
    {
        $this->assertSame($exitCode, Artisan::call('zon:doctor', ['--json' => true]));

        return collect(json_decode(Artisan::output(), true))->keyBy('key')->all();
    }

    public function test_the_doctor_checks_every_part_and_says_where_to_look(): void
    {
        $checks = $this->doctor(0);

        $this->assertSame(['app', 'database', 'migrations', 'redis', 'scheduler', 'queues', 'failed_jobs', 'agents', 'index', 'report_db', 'storage', 'uploads', 'websockets', 'integrations'], array_keys($checks));
        foreach (['database', 'migrations', 'redis', 'scheduler', 'agents', 'index', 'storage'] as $key) {
            $this->assertSame('ok', $checks[$key]['status'], $key.': '.$checks[$key]['detail']);
            $this->assertNull($checks[$key]['hint']);
        }
        $this->assertStringEndsWith('· qwen3.6-35b-a3b-fp8', $checks['agents']['detail'], 'the model the AI node serves');
    }

    public function test_a_stopped_scheduler_fails_the_doctor_with_the_fix(): void
    {
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        $checks = $this->doctor(1);

        $this->assertSame('fail', $checks['scheduler']['status']);
        $this->assertStringContainsString('schedule:run', (string) $checks['scheduler']['hint']);
    }

    public function test_an_ai_dependency_down_is_a_warning_while_the_fallback_answers(): void
    {
        $this->qdrantUp = false;

        $checks = $this->doctor(0);

        $this->assertSame('warn', $checks['agents']['status']);
        $this->assertStringContainsString('Qdrant ✗', $checks['agents']['detail']);
    }

    public function test_the_health_page_shows_each_check_to_permitted_staff(): void
    {
        $this->signInStaff(Permission::ViewHealth);

        Livewire::test(HealthMonitor::class)
            ->assertOk()
            ->assertSee('قاعدة البيانات')
            ->assertSee('خدمة الوكلاء (الذكاء)')
            ->assertSee('سليم');
    }

    public function test_a_trace_id_leads_to_everything_its_request_left_behind(): void
    {
        $this->signInSuperadmin();
        Context::add(Trace::KEY, 'trace-0001-abc');
        $call = AiRequest::query()->create(['agent' => AiAgent::Chat, 'status' => AiCallStatus::Fallback, 'request_hash' => str_repeat('a', 64), 'trace_id' => 'trace-0001-abc']);
        $other = AiRequest::query()->create(['agent' => AiAgent::Chat, 'status' => AiCallStatus::Ok, 'request_hash' => str_repeat('b', 64), 'trace_id' => 'trace-0002-xyz']);
        IndexEvent::query()->create(['entity' => IndexEntity::KbDocument, 'entity_id' => 3, 'action' => IndexAction::Create, 'version' => 1, 'payload_hash' => sha1('x'), 'trace_id' => 'trace-0001-abc']);
        activity()->log('تعديل تجريبي'); // any audit entry takes the request's trace id

        Livewire::test(TraceLookup::class)
            ->fillForm(['trace' => 'trace-0001-abc'])
            ->call('lookup')
            ->assertSee('نداءات الذكاء: 1')
            ->assertSee('إشارات الفهرسة: 1')
            ->assertSee('سجل التدقيق: 1');

        Livewire::test(ListAiRequests::class)
            ->filterTable('trace', ['trace' => 'trace-0001-abc'])
            ->assertCanSeeTableRecords([$call])
            ->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ListActivities::class)
            ->filterTable('trace', ['trace' => 'trace-0001-abc'])
            ->assertCanSeeTableRecords(Activity::query()->where('description', 'تعديل تجريبي')->get());
    }

    public function test_a_supervisor_without_the_permission_cannot_open_either_page(): void
    {
        $this->signInStaff(Permission::ViewAi);

        $this->get(HealthMonitor::getUrl())->assertForbidden();
        $this->get(TraceLookup::getUrl())->assertForbidden();
    }
}
