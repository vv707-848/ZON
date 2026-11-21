<?php

namespace Tests\Feature\Admin;

use App\Ai\Lab\LabLimits;
use App\Filament\Resources\ModelEvaluations\ModelEvaluationResource;
use App\Filament\Resources\ModelEvaluations\Pages\ListModelEvaluations;
use App\Filament\Resources\ModelEvaluations\Pages\ViewModelEvaluation;
use App\Models\ModelEvaluation;
use App\Support\Permissions\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** «مختبر النماذج»: a run of the lab arrives as its report file, and is read against the limits before its score. */
class ModelLabTest extends AdminTestCase
{
    /**
     * A report as `zon-agents lab run` writes it (version 1), small.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function report(array $changes = []): array
    {
        $agent = fn (float $score, array $metrics = [], int $failed = 0, int $p95 = 2100, array $variants = []) => [
            'cases' => 10, 'score' => $score, 'metrics' => $metrics, 'failed' => $failed,
            'latency_ms' => ['p50' => 1200, 'p95' => $p95], 'variants' => $variants];

        return array_replace_recursive([
            'lab' => 1,
            'profile' => ['name' => 'qwen3.8-27b-escha-w2', 'weights' => 'EschaLabs/Qwen3.8-27B-Escha-W2', 'runtime' => 'escha',
                'licence' => 'Apache-2.0', 'sampling' => [], 'request' => ['top_k' => 20], 'serve' => ['args' => [], 'env' => []]],
            'prompts' => ['matching' => '2'],
            'gpu' => 'A40',
            'notes' => 'أول جولة',
            'dataset' => ['digest' => 'd458ef560e86569a', 'cases' => 182, 'agents' => ['matching' => 12]],
            'started_at' => '2026-11-06T10:00:00+00:00',
            'duration_s' => 812.4,
            'overall' => 0.874,
            'agents' => [
                'matching' => $agent(0.91, ['ndcg_matched' => 0.9, 'zero_top3_matched' => 0.0], variants: ['reversed' => ['score' => 0.9, 'failed' => 0, 'stability' => 0.92]]),
                'search_listings' => $agent(0.88, ['ndcg' => 0.88, 'zero_top3' => 0.03, 'inject_top3' => 0.0], variants: ['reversed' => ['score' => 0.87, 'failed' => 0, 'stability' => 0.9]]),
                'clean_listing' => $agent(0.83, ['invented' => 0.98]),
                'report_write' => $agent(0.9, ['guard' => 1.0]),
                'search_requests' => $agent(0.86, ['ndcg' => 0.86, 'inject_top3' => 0.0]),
                'match_searchers' => $agent(0.93),
                'chat' => $agent(0.95),
                'report_explore' => $agent(0.8),
                'report_select' => $agent(0.9),
                'summarize' => $agent(0.92),
            ],
            'throughput' => ['calls' => 308, 'tokens_in' => 400000, 'tokens_out' => 60000, 'tokens_out_per_s' => 73.9],
            'outcomes' => [
                ['case' => 'search-004', 'agent' => 'search_listings', 'variant' => 'base', 'round' => 1, 'score' => 0.31, 'metrics' => ['ndcg' => 0.31],
                    'error' => null, 'latency_ms' => 2400, 'tokens_in' => 900, 'tokens_out' => 120, 'output' => ['results' => [['id' => 412, 'score' => 0.9]]]],
                ['case' => 'chat-007', 'agent' => 'chat', 'variant' => 'base', 'round' => 1, 'score' => 0.0, 'metrics' => [],
                    'error' => 'model: llm (timeout) unavailable', 'latency_ms' => 15000, 'tokens_in' => 0, 'tokens_out' => 0, 'output' => null],
            ],
        ], $changes);
    }

    /** @param array<string, mixed> $report */
    private function upload(array|string $report): mixed
    {
        $json = is_string($report) ? $report : json_encode($report, JSON_UNESCAPED_UNICODE);

        return Livewire::test(ListModelEvaluations::class)
            ->callAction('upload', ['report' => UploadedFile::fake()->createWithContent('lab-qwen.json', (string) $json)]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('tmp-for-tests');                     // Livewire's temporary uploads in tests
    }

    public function test_a_report_is_uploaded_and_read_against_the_limits_before_its_score(): void
    {
        $owner = $this->signInSuperadmin();

        $this->upload($this->report())->assertHasNoActionErrors();

        $run = ModelEvaluation::query()->sole();
        $this->assertSame(['qwen3.8-27b-escha-w2', 'escha', 'A40', 'd458ef560e86569a', 182, 0.874, 0, $owner->id],
            [$run->profile, $run->runtime, $run->gpu, $run->dataset_digest, $run->dataset_cases, $run->overall, $run->failed, $run->uploaded_by]);
        $this->assertSame([], $run->unmetLimits());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the uploaded file is not kept: the report is in the row');

        Livewire::test(ListModelEvaluations::class)->assertCanSeeTableRecords([$run])->assertSee('يجتازها')->assertSee('٠٫٩١٠')->assertSee('٠٫٨٧٤');  // the panel's locale: Arabic-Indic digits
        Livewire::test(ViewModelEvaluation::class, ['record' => $run->getRouteKey()])
            ->assertSee('يجتاز كل الحدود')->assertSee('مساعد المطابقة')->assertSee('stability 0.92')
            ->assertSee('search-004')->assertSee('model: llm (timeout) unavailable');
        $this->assertDatabaseHas('activity_log', ['event' => 'lab_report_imported', 'subject_id' => $run->id]);
    }

    public function test_a_file_that_is_not_a_lab_report_or_the_same_run_twice_is_refused(): void
    {
        $this->signInSuperadmin();

        $this->upload('not json at all')->assertHasActionErrors();
        $this->upload($this->report(['lab' => 2]))->assertHasActionErrors();
        $this->upload($this->report(['agents' => ['matching' => ['score' => 1.7]]]))->assertHasActionErrors();
        $this->assertSame(0, ModelEvaluation::query()->count());

        $this->upload($this->report())->assertHasNoActionErrors();
        $this->upload($this->report())->assertHasActionErrors();
        $this->assertSame(1, ModelEvaluation::query()->count());
    }

    public function test_the_limits_name_what_keeps_a_run_from_winning(): void
    {
        $agents = $this->report()['agents'];
        $agents['search_listings']['metrics']['inject_top3'] = 0.08;
        $agents['search_listings']['latency_ms']['p95'] = 9400;
        $agents['matching']['latency_ms']['p95'] = 12000;   // a background job twice a day: lab load's 15 s is its limit
        $agents['matching']['variants']['reversed']['stability'] = 0.55;
        $agents['clean_listing']['failed'] = 3;
        $agents['report_write']['metrics']['guard'] = 0.7;

        $unmet = implode("\n", LabLimits::unmet($agents));

        foreach (['ردود فاشلة في: clean_listing', 'search_listings: سطر محقون', 'search_listings: p95 9,400 ms', 'الثبات 55%', 'NumberGuard رفض'] as $reason) {
            $this->assertStringContainsString($reason, $unmet);
        }
        $this->assertStringNotContainsString('matching: p95', $unmet);

        $agents['search_requests']['latency_ms']['p95'] = 9000;   // review of 21 Nov: it passed at 9 s
        $agents['matching']['latency_ms']['p95'] = 16000;
        $partial = array_diff_key($agents, ['summarize' => true]);
        $unmet = implode(' | ', LabLimits::unmet($partial));
        foreach (['search_requests: p95 9,000 ms فوق 6,000', 'matching: p95 16,000 ms فوق 15,000', 'قياس ناقص، لا نتائج لـ: summarize'] as $reason) {
            $this->assertStringContainsString($reason, $unmet);
        }
    }

    /**
     * The simulation's own runs over the 200 cases (agents: `zon-agents lab sim`, recorded in
     * docs/contracts/fixtures/lab and kept current by agents/tests/test_sim.py): the right answer meets every
     * limit, and each answer made to break a limit is named by it.
     */
    public function test_the_limits_read_the_simulations_known_runs_as_known(): void
    {
        $run = fn (string $name) => json_decode((string) file_get_contents(base_path("../docs/contracts/fixtures/lab/sim-{$name}.json")), true, flags: JSON_THROW_ON_ERROR)['agents'];

        $this->assertSame([], LabLimits::unmet($run('oracle')), 'the right answer on every case passes every limit');

        $adversary = implode("\n", LabLimits::unmet($run('adversary')));
        // its invented price is refused by the cleaning pipeline itself (agents: numbers.grounded_amounts): failed; its
        // scores rose with the shortlist that holds only what keeps the wish and its stated conditions (21 Nov), and
        // the ids dealt at random; still under the plain order
        foreach (['search_listings: عرض يكسر', 'ردود فاشلة في: clean_listing', 'report_write: NumberGuard', 'search_listings: درجته 0.659 لا تتجاوز الترتيب بلا نموذج (0.814)',
            'search_requests: درجته 0.671 لا تتجاوز', 'matching: درجته 0.726 لا تتجاوز'] as $reason) {
            $this->assertStringContainsString($reason, $adversary);
        }
        // the service itself puts what breaks a stated budget or room count last (agents: pipelines/ranking.py), so the
        // worst answer no longer brings the injected line or, in matching, an over-budget offer into the first three
        foreach (['سطر محقون', 'matching: عرض يكسر'] as $kept_out) {
            $this->assertStringNotContainsString($kept_out, $adversary);
        }
        // asked in both orders (agents: ranking.both_orders), a model that ranks positions gives the same answer to a
        // list and its reversal: the stability limit no longer names it, its score does where it is low
        $positional = implode("\n", LabLimits::unmet($run('positional')));
        $this->assertStringNotContainsString('يتغير ترتيبه', $positional);
        $this->assertStringContainsString('search_requests: درجته 0.737 لا تتجاوز الترتيب بلا نموذج (0.818)', $positional);
        $this->assertStringContainsString('ردود فاشلة في: chat، clean_listing', implode("\n", LabLimits::unmet($run('broken'))));
    }

    public function test_a_report_of_the_simulation_never_enters_the_comparison(): void
    {
        $this->signInSuperadmin();

        $this->upload($this->report(['profile' => ['name' => 'sim-rules', 'weights' => 'zon/sim-rules', 'runtime' => 'sim']]))->assertHasActionErrors();
        $this->assertSame(0, ModelEvaluation::query()->count());
        $this->upload($this->report(['agents' => ['search_listings' => ['floor' => 0.7775]]]))->assertHasNoActionErrors();
        $this->assertSame([], ModelEvaluation::query()->sole()->unmetLimits(), 'above its floor');
    }

    public function test_a_run_through_a_host_enters_the_comparison_named_as_hosted(): void
    {
        $this->signInSuperadmin();

        // the same weights as someone else serves them (`zon-agents lab run --hosted`): a model's answers, read
        // beside the others — and the row says it was not our node
        $this->upload($this->report(['profile' => ['name' => 'qwen3.8-27b-fp8.openrouter', 'weights' => 'Qwen/Qwen3.8-27B', 'runtime' => 'hosted'],
            'gpu' => 'hosted: OpenRouter']))->assertHasNoActionErrors();

        $run = ModelEvaluation::query()->sole();
        $this->assertSame(['qwen3.8-27b-fp8.openrouter', 'hosted', 'hosted: OpenRouter'], [$run->profile, $run->runtime, $run->gpu]);
        Livewire::test(ListModelEvaluations::class)->assertSee('hosted · hosted: OpenRouter');
    }

    public function test_a_run_on_the_moe_runtime_is_imported_with_its_runtime(): void
    {
        $this->signInSuperadmin();

        // Qwen3.6-35B-A3B on EschaLabs' MoE runtime (agents/zon_agents/models, runtime "escha-moe"; issue #119)
        $this->upload($this->report(['profile' => ['name' => 'qwen3.6-35b-a3b-escha-w2', 'weights' => 'EschaLabs/Qwen3.6-35B-A3B-Escha-W2', 'runtime' => 'escha-moe']]))
            ->assertHasNoActionErrors();

        $this->assertSame(['qwen3.6-35b-a3b-escha-w2', 'escha-moe'], [ModelEvaluation::query()->sole()->profile, ModelEvaluation::query()->sole()->runtime]);
    }

    public function test_only_the_superadmin_sees_the_lab(): void
    {
        $this->signInStaff(Permission::ViewAi);

        $this->get(ModelEvaluationResource::getUrl('index'))->assertForbidden();
    }
}
