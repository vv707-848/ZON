<?php

namespace App\Actions\Admin;

use App\Filament\Support\Audit;
use App\Models\ModelEvaluation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * A model lab report (the JSON `zon-agents lab run` writes on the AI node) into «مختبر النماذج». The file is
 * checked against the report's version 1 before anything is kept; the same run uploaded twice is refused.
 */
final class ImportLabReport
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __invoke(string $json, User $by): ModelEvaluation
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['report' => 'الملف أكبر من 20 ميغابايت.']);
        }
        try {
            $report = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['report' => 'ليس ملف JSON صالحاً.']);
        }
        $data = Validator::make(is_array($report) ? $report : [], [
            'lab' => ['required', 'integer', 'in:1'],
            'profile.name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.\-]{2,63}$/'],
            'profile.weights' => ['required', 'string', 'max:200'],
            'profile.runtime' => ['required', 'string', 'in:vllm,sglang,escha'],
            'gpu' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'dataset.digest' => ['required', 'string', 'regex:/^[0-9a-f]{16}$/'],
            'dataset.cases' => ['required', 'integer', 'min:1'],
            'started_at' => ['required', 'date'],
            'duration_s' => ['required', 'numeric', 'min:0'],
            'overall' => ['required', 'numeric', 'between:0,1'],
            'agents' => ['required', 'array', 'min:1'],
            'agents.*.cases' => ['required', 'integer', 'min:1'],
            'agents.*.score' => ['required', 'numeric', 'between:0,1'],
            'agents.*.failed' => ['required', 'integer', 'min:0'],
            'agents.*.metrics' => ['present', 'array'],
            'agents.*.latency_ms.p50' => ['required', 'integer', 'min:0'],
            'agents.*.latency_ms.p95' => ['required', 'integer', 'min:0'],
            'agents.*.variants' => ['present', 'array'],
            'throughput' => ['required', 'array'],
            'outcomes' => ['required', 'array', 'max:20000'],
        ], ['lab.in' => 'إصدار التقرير غير معروف (المعروف 1).'])->validate();

        $started = Carbon::parse($data['started_at']);
        $taken = ModelEvaluation::query()->where('profile', $data['profile']['name'])->where('started_at', $started)
            ->where('dataset_digest', $data['dataset']['digest'])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['report' => 'رُفع تقرير هذه الجولة من قبل.']);
        }

        $evaluation = ModelEvaluation::query()->create([
            'profile' => $data['profile']['name'],
            'weights' => $data['profile']['weights'],
            'runtime' => $data['profile']['runtime'],
            'gpu' => $data['gpu'] ?? null,
            'notes' => $data['notes'] ?? null,
            'dataset_digest' => $data['dataset']['digest'],
            'dataset_cases' => $data['dataset']['cases'],
            'overall' => $data['overall'],
            'agents' => $report['agents'],
            'throughput' => $report['throughput'],
            'failed' => array_sum(array_map(fn (array $agent) => (int) $agent['failed'], $report['agents'])),
            'started_at' => $started,
            'duration_s' => $data['duration_s'],
            'report' => $report,
            'uploaded_by' => $by->id,
        ]);
        Audit::log('lab_report_imported', 'رفع نتيجة مختبر النماذج', $evaluation, ['profile' => $evaluation->profile, 'overall' => $evaluation->overall], $by);

        return $evaluation;
    }
}
