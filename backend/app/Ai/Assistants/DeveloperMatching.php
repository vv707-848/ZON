<?php

namespace App\Ai\Assistants;

use App\Ai\AiGateway;
use App\Ai\AiResult;
use App\Ai\Retrieval\ProjectAudience;
use App\Enums\AiAgent;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectUnit;

/** A developer's project → the seekers whose wishes fit it, each with the closest unit model (or none). */
final class DeveloperMatching
{
    public function __construct(private readonly AiGateway $ai, private readonly ProjectAudience $audience) {}

    /** @return array{results: list<array{wish: BuyerPreference, score: ?float, unit: ?ProjectUnit}>, ai: array<string, mixed>} */
    public function searchers(Project $project, Customer $developer): array
    {
        $units = $project->units()->where('available_count', '>', 0)->limit(100)->get()->keyBy('id');
        $wishes = $this->audience->searchers($project);
        if ($units->isEmpty() || $wishes->isEmpty()) {
            return ['results' => [], 'ai' => AiResult::skipped()];
        }
        $byId = $wishes->keyBy('id');

        $result = $this->ai->call(AiAgent::MatchSearchers, [
            'project' => ['type' => $project->property_type->value, 'neighborhood' => $project->neighborhood, 'context' => mb_substr($project->publicDescription(), 0, 600)],
            'units' => $units->map(fn (ProjectUnit $u) => [
                'id' => $u->id, 'model' => $u->model_name, 'type' => $u->property_type->value,
                'area' => ($a = $u->area ?? $u->area_built ?? $u->area_land) === null ? null : (float) $a,
                'bedrooms' => $u->bedrooms, 'price' => (float) $u->price, 'desc' => mb_substr($u->publicDescription(), 0, 400),
            ])->values()->all(),
            'searchers' => $wishes->map(fn (BuyerPreference $w) => ProjectAudience::toAgent($w))->values()->all(),
            'limit' => (int) config('zon.ai.results'),
        ], check: fn (array $out) => ['results' => array_map(
            fn (array $r) => [...$r, 'unit_id' => isset($r['unit_id']) && $units->has((int) $r['unit_id']) ? (int) $r['unit_id'] : null],   // an invented unit becomes none
            AiGateway::ranked($out['results'] ?? [], $byId->keys()->all(), extra: ['unit_id']),
        )], customer: $developer);

        $ranked = $result->output['results'] ?? null;
        $rows = $ranked === null
            ? $wishes->take((int) config('zon.ai.results'))->map(fn ($w) => ['wish' => $w, 'score' => null, 'unit' => null])->values()->all()
            : array_map(fn (array $r) => ['wish' => $byId[$r['id']], 'score' => $r['score'], 'unit' => $r['unit_id'] === null ? null : $units[$r['unit_id']]], $ranked);

        return ['results' => $rows, 'ai' => $result->meta()];
    }
}
