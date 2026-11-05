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
            'profile' => ['name' => 'qwen3.6-35b-a3b-fp8', 'weights' => 'RedHatAI/Qwen3.6-35B-A3B-FP8-dynamic', 'runtime' => 'vllm',
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
        $this->assertSame(['qwen3.6-35b-a3b-fp8', 'vllm', 'A40', 'd458ef560e86569a', 182, 0.874, 0, $owner->id],
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
        $agents['matching']['latency_ms']['p95'] = 9400;
        $agents['matching']['variants']['reversed']['stability'] = 0.55;
        $agents['clean_listing']['failed'] = 3;
        $agents['report_write']['metrics']['guard'] = 0.7;

        $unmet = implode("\n", LabLimits::unmet($agents));

        foreach (['ردود فاشلة في: clean_listing', 'search_listings: سطر محقون', 'matching: p95 9,400 ms', 'الثبات 55%', 'NumberGuard رفض'] as $reason) {
            $this->assertStringContainsString($reason, $unmet);
        }
    }

    public function test_only_the_superadmin_sees_the_lab(): void
    {
        $this->signInStaff(Permission::ViewAi);

        $this->get(ModelEvaluationResource::getUrl('index'))->assertForbidden();
    }
}
