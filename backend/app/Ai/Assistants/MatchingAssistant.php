<?php

namespace App\Ai\Assistants;

use App\Ai\AiGateway;
use App\Ai\AiResult;
use App\Ai\Retrieval\ListingCandidates;
use App\Ai\Retrieval\Need;
use App\Ai\Retrieval\QueryExpander;
use App\Ai\Retrieval\UnitCandidates;
use App\Enums\AiAgent;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ProjectUnit;

/**
 * «مساعد المطابقة»: three lists — offers matching the wish, offers that broaden it (other types within the budget),
 * and project unit models (grouped into project cards by Laravel). Nothing at all → a search miss is recorded and
 * the app suggests posting a request (suggest_request).
 */
final class MatchingAssistant
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly ListingCandidates $listings,
        private readonly UnitCandidates $units,
        private readonly QueryExpander $expander,
    ) {}

    /**
     * @return array{matched: list<array{model: Listing, score: ?float}>, broaden: list<array{model: Listing, score: ?float}>,
     *               units: list<array{model: ProjectUnit, score: ?float}>, suggest_request: bool, ai: array<string, mixed>}
     */
    public function match(Need $need, Customer $customer): array
    {
        $matched = $this->listings->matched($need, $customer);
        $broaden = $this->listings->broaden($need, $customer, $matched->modelKeys());
        $units = $this->units->for($need, $customer);
        $limit = (int) config('zon.ai.results');

        if ($matched->isEmpty() && $broaden->isEmpty() && $units->isEmpty()) {
            SearchMisses::record('matching', $need, $customer);

            return ['matched' => [], 'broaden' => [], 'units' => [], 'suggest_request' => true, 'ai' => AiResult::skipped()];
        }

        $result = $this->ai->call(AiAgent::Matching, [
            'need' => $need->toAgent(),
            'terms' => $this->expander->terms($need->freeText),
            'matched' => $matched->map(fn (Listing $l) => ListingCandidates::toAgent($l))->values()->all(),
            'broaden' => $broaden->map(fn (Listing $l) => ListingCandidates::toAgent($l))->values()->all(),
            'units' => $units->map(fn (ProjectUnit $u) => UnitCandidates::toAgent($u))->values()->all(),
            'limit' => $limit,
        ], check: fn (array $out) => [
            'matched' => AiGateway::ranked($out['matched'] ?? [], $matched->modelKeys()),
            'broaden' => AiGateway::ranked($out['broaden'] ?? [], $broaden->modelKeys()),
            'units' => AiGateway::ranked($out['units'] ?? [], $units->modelKeys(), 30),
        ], customer: $customer);

        $pick = function (string $list, $models) use ($result, $limit): array {
            $byId = $models->keyBy('id');
            $ranked = $result->output[$list] ?? null;

            return $ranked === null
                ? $models->take($list === 'units' ? 30 : $limit)->map(fn ($m) => ['model' => $m, 'score' => null])->values()->all()
                : array_map(fn (array $r) => ['model' => $byId[$r['id']], 'score' => $r['score']], $ranked);
        };

        return [
            'matched' => $pick('matched', $matched),
            'broaden' => $pick('broaden', $broaden),
            'units' => $pick('units', $units),
            'suggest_request' => $matched->isEmpty(),
            'ai' => $result->meta(),
        ];
    }
}
