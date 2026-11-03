<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Assistants\ChatAssistant;
use App\Ai\Assistants\DeveloperMatching;
use App\Ai\Assistants\MatchingAssistant;
use App\Ai\Assistants\NoteSummarizer;
use App\Ai\Assistants\SemanticSearch;
use App\Ai\Capture\TrainingCapture;
use App\Ai\Masking\AiMasking;
use App\Ai\Quota\AiQuota;
use App\Ai\Retrieval\Need;
use App\Ai\Retrieval\ProjectAudience;
use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertiserResource;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SearchRequestResource;
use App\Http\Resources\UnitResource;
use App\Models\ChatMessage;
use App\Models\ClientNote;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\OwnerNote;
use App\Models\Project;
use App\Models\PropertyOwner;
use App\Models\SearchFeedback;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use App\Services\Billing\Entitlements;
use App\Support\Api;
use App\Support\Ownership;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI services of the app. Each answer carries meta.ai = {status}: ok/cached = ranked by the model; degraded =
 * the agents' hybrid order without the model; fallback/timeout/rejected = the SQL order; skipped = no call was
 * needed. A result is a record and its score — the app shows no explanation under it.
 */
class AiController extends Controller
{
    public function quota(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $quota = app(AiQuota::class);
        $uses = [];
        foreach (AiQuota::USES as $use) {
            $limit = Entitlements::for($me)->aiDaily($use);
            $used = $quota->used($use, $me);
            $uses[$use] = ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
        }

        return Api::ok(['uses' => $uses, 'resets_at' => AiQuota::resetsAt()->toIso8601String()]);
    }

    /** «البحث الدلالي» over listings: city + neighbourhood + type are required; the free text says the rest. */
    public function search(Request $request, SemanticSearch $search): JsonResponse
    {
        $out = $search->listings($this->need($request), $request->user('api'));

        return Api::ok([
            'results' => array_map(fn (array $r) => ['listing' => new ListingResource($r['model']), 'score' => $r['score']], $out['results']),
        ], ['ai' => $out['ai']]);
    }

    /** A broker searches the requests his offer could answer (a plan feature). */
    public function searchRequests(Request $request, SemanticSearch $search): JsonResponse
    {
        $broker = $request->user('api');
        if (! Entitlements::for($broker)->canSearchRequests()) {
            throw ApiException::of(ErrorCode::PaymentRequired, ['upgrade' => AiQuota::offers($broker, 'search')]);
        }
        $out = $search->requests($this->need($request), $broker);

        return Api::ok([
            'results' => array_map(fn (array $r) => ['request' => new SearchRequestResource($r['model']), 'score' => $r['score']], $out['results']),
        ], ['ai' => $out['ai']]);
    }

    /** «مساعد المطابقة»: the seeker's wish (or the need sent) → offers, broader offers, and projects. */
    public function matching(Request $request, MatchingAssistant $matching): JsonResponse
    {
        $me = $request->user('api');
        if ($request->filled('city')) {
            $need = $this->need($request);
        } else {
            $wish = $me->preferences()->where('is_active', true)->when($request->integer('preference_id'), fn ($q, $id) => $q->whereKey($id))->latest('updated_at')->first()
                ?? throw ApiException::of(ErrorCode::PreferenceRequired);
            $need = Need::fromPreference($wish);
            $wish->forceFill(['matches_seen_at' => now()])->saveQuietly();
        }
        $out = $matching->match($need, $me);

        $projects = [];
        foreach ($out['units'] as $row) {
            $unit = $row['model'];
            $projects[$unit->project_id] ??= ['project' => new ProjectResource($unit->project), 'units' => []];
            $projects[$unit->project_id]['units'][] = ['unit' => new UnitResource($unit), 'score' => $row['score']];
        }
        $projects = array_values($projects);
        $listing = fn (array $r) => ['listing' => new ListingResource($r['model']), 'score' => $r['score']];

        return Api::ok([
            'matched' => array_map($listing, $out['matched']),
            'broaden' => array_map($listing, $out['broaden']),
            'projects' => $projects,
            'suggest_request' => $out['suggest_request'],
        ], ['ai' => $out['ai']]);
    }

    /** The developer's project → seekers whose wishes fit, each with the closest unit model. */
    public function searchers(Request $request, Project $project, DeveloperMatching $matching): JsonResponse
    {
        $this->ownPublished($request, $project);
        $out = $matching->searchers($project, $request->user('api'));

        return Api::ok(array_map(fn (array $r) => [
            'seeker' => new AdvertiserResource($r['wish']->customer),
            'wish' => ['neighborhoods' => $r['wish']->neighborhoods, 'property_types' => $r['wish']->property_types,
                'budget_max' => $r['wish']->budget_max === null ? null : (float) $r['wish']->budget_max,
                'notes' => $r['wish']->notes === null ? null : AiMasking::text($r['wish']->notes)],   // what he wants, not how to reach him
            'score' => $r['score'],
            'unit' => $r['unit'] === null ? null : ['id' => $r['unit']->id, 'model_name' => $r['unit']->model_name],
        ], $out['results']), ['ai' => $out['ai']]);
    }

    /** Brokers of the project's city by the number of their matching clients — never the clients themselves. */
    public function brokers(Request $request, Project $project, ProjectAudience $audience): JsonResponse
    {
        $this->ownPublished($request, $project);

        return Api::ok(array_map(fn (array $row) => ['broker' => new AdvertiserResource($row['broker']), 'clients' => $row['clients']], $audience->brokers($project)));
    }

    public function chat(Request $request, ChatAssistant $chat): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:'.Settings::int(Setting::ChatMaxChars)]]);
        $out = $chat->ask($request->user('api'), trim($data['message']));
        $ai = $out['ai'];
        unset($out['ai']);

        return Api::ok($out, ['ai' => $ai]);
    }

    public function chatHistory(Request $request): JsonResponse
    {
        return Api::ok(ChatMessage::query()->where('customer_id', $request->user('api')->id)->latest('id')->limit(50)->get()->reverse()->values()
            ->map(fn (ChatMessage $m) => ['question' => $m->question, 'answer' => $m->answer, 'at' => $m->created_at?->toIso8601String()]));
    }

    /** The summary button: client (broker · developer), owner (broker), tasks (anyone). */
    public function summarize(Request $request, NoteSummarizer $summarizer): JsonResponse
    {
        $me = $request->user('api');
        $data = $request->validate(['scope' => ['required', 'in:client,owner,tasks'], 'id' => ['required_unless:scope,tasks', 'nullable', 'integer']]);
        [$refId, $names, $notes] = match ($data['scope']) {
            'client' => $this->clientNotes($me, (int) $data['id']),
            'owner' => $this->ownerNotes($me, (int) $data['id']),
            default => [$me->id, [], $me->tasks()->latest()->limit(60)->get()->map(fn ($t) => [
                'category' => $t->done_at === null ? 'open' : 'done', 'date' => (string) $t->created_at?->toDateString(), 'text' => trim($t->title.'. '.$t->body)])->values()->all()],
        };
        $out = $summarizer->summarize($me, $data['scope'], $refId, $notes, $names);
        $summary = $out['summary'];

        return Api::ok($summary->only(['summary', 'signals', 'recommended_action', 'confidence']) + ['updated_at' => $summary->updated_at?->toIso8601String()], ['ai' => $out['ai']]);
    }

    /**
     * «مطابق / غير مطابق» under a result; «غير مطابق» needs its reason (it teaches the matching). A tester's mark
     * is validated the same and kept nowhere: it would teach the matching a reviewer's «تجربة» (Sandbox).
     */
    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'context' => ['required', 'in:search,matching,requests'],
            'result_type' => ['required', 'in:listing,project_unit,request,searcher'],
            'result_id' => ['required', 'integer'],
            'is_match' => ['required', 'boolean'],
            'reason' => ['required_if:is_match,false', 'nullable', 'string', 'max:500'],
            'query' => ['nullable', 'array'],
        ]);
        if ($request->user('api')->is_tester) {
            return Api::created();
        }
        SearchFeedback::query()->create([
            'customer_id' => $request->user('api')->id,
            'context' => $data['context'],
            'result_type' => $data['result_type'],
            'result_id' => $data['result_id'],
            'is_match' => $data['is_match'],
            'reason' => isset($data['reason']) ? AiMasking::text($data['reason']) : null,
            'query_snapshot' => AiMasking::payload(array_intersect_key($data['query'] ?? [], array_flip(['city', 'neighborhood', 'neighborhoods', 'property_type', 'property_types', 'purpose', 'budget_min', 'budget_max', 'bedrooms', 'query']))),
            'created_at' => now(),
        ]);
        if (! empty($data['reason'])) {
            TrainingCapture::feedback($data['reason'], (bool) $data['is_match'], $request->user('api'));
        }

        return Api::created();
    }

    private function need(Request $request): Need
    {
        $data = $request->validate([
            'city' => ['required', 'string', new KnownCity],
            'neighborhood' => ['required_without:neighborhoods', 'nullable', 'string', new KnownNeighborhood($request->string('city')->value() ?: null)],
            'neighborhoods' => ['required_without:neighborhood', 'nullable', 'array', 'max:10', new KnownNeighborhood($request->string('city')->value() ?: null)],
            'property_type' => ['required_without:property_types', 'nullable', Rule::enum(PropertyType::class)],
            'property_types' => ['required_without:property_type', 'nullable', 'array', 'max:10'],
            'property_types.*' => [Rule::enum(PropertyType::class)],
            'purpose' => ['nullable', Rule::enum(Purpose::class)],
            'budget_min' => ['nullable', 'numeric', 'gte:0'],
            'budget_max' => ['nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'between:0,50'],
            'query' => ['nullable', 'string', 'max:'.(int) config('zon.ai.free_text_max')],
        ]);

        return Need::fromInput(array_filter($data, fn ($v) => $v !== null));
    }

    private function ownPublished(Request $request, Project $project): void
    {
        Ownership::assert($project, $request->user('api'));
        if ($project->status !== ListingStatus::Published) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
    }

    /** @return array{0: int, 1: list<string>, 2: list<array<string, string>>} */
    private function clientNotes(Customer $me, int $id): array
    {
        $client = CrmClient::query()->find($id) ?? throw ApiException::of(ErrorCode::NotFound);
        Ownership::assert($client, $me);

        return [$client->id, [(string) $client->name], $client->notes()->limit(60)->get()->map(fn (ClientNote $n) => ['category' => $n->category->value, 'date' => (string) $n->created_at?->toDateString(), 'text' => (string) $n->body])->values()->all()];
    }

    /** @return array{0: int, 1: list<string>, 2: list<array<string, string>>} */
    private function ownerNotes(Customer $me, int $id): array
    {
        if ($me->role !== CustomerRole::Broker) {
            throw ApiException::of(ErrorCode::RoleForbidden);
        }
        $owner = PropertyOwner::query()->find($id) ?? throw ApiException::of(ErrorCode::NotFound);
        Ownership::assert($owner, $me);

        return [$owner->id, [(string) $owner->name], $owner->notes()->limit(60)->get()->map(fn (OwnerNote $n) => ['category' => $n->category->value, 'date' => (string) $n->created_at?->toDateString(), 'text' => (string) $n->body])->values()->all()];
    }
}
