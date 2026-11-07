<?php

namespace App\Ai\Assistants;

use App\Ai\AiGateway;
use App\Ai\AiResult;
use App\Ai\Retrieval\ListingCandidates;
use App\Ai\Retrieval\Need;
use App\Ai\Retrieval\QueryExpander;
use App\Ai\Retrieval\RequestCandidates;
use App\Enums\AiAgent;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SearchRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * «البحث الدلالي»: SQL candidates → the model ranks them (≤ 10) → only ids we offered come back, each with its
 * score. Without the model: the SQL order (closest price, newest).
 */
final class SemanticSearch
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly ListingCandidates $listings,
        private readonly RequestCandidates $requests,
        private readonly QueryExpander $expander,
    ) {}

    /** @return array{results: list<array{model: Listing, score: ?float}>, ai: array<string, mixed>} */
    public function listings(Need $need, Customer $customer): array
    {
        $candidates = $this->listings->matched($need, $customer);

        return $this->rank(AiAgent::SearchListings, $need, $customer, $candidates, fn (Listing $l) => ListingCandidates::toAgent($l), 'search');
    }

    /** @return array{results: list<array{model: SearchRequest, score: ?float}>, ai: array<string, mixed>} */
    public function requests(Need $need, Customer $broker): array
    {
        $candidates = $this->requests->for($need, $broker);

        return $this->rank(AiAgent::SearchRequests, $need, $broker, $candidates, fn (SearchRequest $r) => RequestCandidates::toAgent($r), 'requests');
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $candidates
     * @param  callable(T): array<string, mixed>  $toAgent
     * @return array{results: list<array{model: T, score: ?float}>, ai: array<string, mixed>}
     */
    private function rank(AiAgent $agent, Need $need, Customer $customer, Collection $candidates, callable $toAgent, string $context): array
    {
        if ($candidates->isEmpty()) {
            SearchMisses::record($context, $need, $customer);

            return ['results' => [], 'ai' => AiResult::skipped()];
        }

        $byId = $candidates->keyBy('id');
        $result = $this->ai->call($agent, [
            'need' => $need->toAgent(),
            'terms' => $this->expander->terms($need->freeText),
            'candidates' => $candidates->map($toAgent)->values()->all(),
            'limit' => (int) config('zon.ai.results'),
        ], check: fn (array $out) => ['results' => AiGateway::ranked($out['results'] ?? [], $byId->keys()->all())],
            customer: $customer, known: [$customer->name]);

        $ranked = $result->output['results'] ?? null;
        $rows = $ranked === null
            ? $candidates->take((int) config('zon.ai.results'))->map(fn ($m) => ['model' => $m, 'score' => null])->values()->all()
            : array_map(fn (array $r) => ['model' => $byId[$r['id']], 'score' => $r['score']], $ranked);

        return ['results' => $rows, 'ai' => $result->meta()];
    }
}
