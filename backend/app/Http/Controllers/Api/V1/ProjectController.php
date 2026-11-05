<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\ChangeProjectStatus;
use App\Actions\Projects\DecideProjectTexts;
use App\Actions\Projects\PublishProject;
use App\Actions\Projects\SaveProject;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Enums\ReviewDecision;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Projects\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UnitResource;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\Actor;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Ownership;
use App\Support\PublicView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Projects: the public list and pages, and the developer's own (create · edit · wording · publish · pause). */
class ProjectController extends Controller
{
    private const WITH = ['developer', 'media', 'units.licence', 'units.media'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'city' => ['nullable', 'string'],
            'neighborhood' => ['nullable', 'string'],
            'property_type' => ['nullable', 'string'],
            'delivery' => ['nullable', 'in:ready,offplan'],
            'price_max' => ['nullable', 'numeric', 'gt:0'],
            'per_page' => ['nullable', 'integer', 'between:5,50'],
        ]);
        $viewer = $request->user('api');

        $page = Project::query()->published()->with(['developer', 'media' => fn ($q) => $q->where('is_cover', true), 'units.licence'])
            ->whereNotIn('developer_id', Blocks::hiddenFor($viewer instanceof Customer ? $viewer : null))
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->where('neighborhood', $v))
            ->when($filters['delivery'] ?? null, fn (Builder $q, $v) => $q->where('delivery', $v))
            ->when($filters['property_type'] ?? null, fn (Builder $q, $v) => $q->whereHas('units', fn ($u) => $u->where('property_type', $v)))
            ->when($filters['price_max'] ?? null, fn (Builder $q, $v) => $q->whereHas('units', fn ($u) => $u->where('available_count', '>', 0)->where('price', '<=', $v)))
            ->orderByRaw('featured_until IS NOT NULL AND featured_until > ? DESC', [now()])->orderByDesc('published_at')
            ->paginate((int) ($filters['per_page'] ?? 20));
        $page->getCollection()->each(fn (Project $project) => $this->publicUnits($project));

        return Api::page($page, ProjectResource::class);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->visible($request, $project);
        $project->load(self::WITH);
        if (! $this->isMine($request, $project)) {
            if (! $request->user('api')?->is_tester) {
                $project->increment('views_count');     // a tester's look is not a view
            }
            $this->publicUnits($project);
        }

        return Api::ok(ProjectResource::detail($project));
    }

    public function unit(Request $request, Project $project, ProjectUnit $unit): JsonResponse
    {
        $this->visible($request, $project);
        $unit->load(['licence', 'media']);
        $viewer = $request->user('api');
        if (! PublicView::unit($project, $unit, $viewer instanceof Customer ? $viewer : null)) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        return Api::ok(new UnitResource($unit->setRelation('project', $project)));
    }

    public function mine(Request $request): JsonResponse
    {
        $page = $request->user('api')->projects()->with(['developer', 'media' => fn ($q) => $q->where('is_cover', true), 'units'])->latest('updated_at')->paginate(20);

        return Api::page($page, ProjectResource::class);
    }

    public function store(ProjectRequest $request, SaveProject $save): JsonResponse
    {
        $project = $save($request->customer(), null, $request->columns(), array_map('intval', $request->input('media_ids', [])), $request->integer('cover_id') ?: null);

        return Api::created(ProjectResource::detail($project->load(self::WITH)));
    }

    public function update(ProjectRequest $request, Project $project, SaveProject $save): JsonResponse
    {
        Ownership::assert($project, $request->customer());
        $save($request->customer(), $project, $request->columns(),
            $request->has('media_ids') ? array_map('intval', $request->input('media_ids', [])) : null, $request->integer('cover_id') ?: null);

        return Api::ok(ProjectResource::detail($project->load(self::WITH)));
    }

    /** Both reviews on one screen: the project's wording and every unit model's. */
    public function review(Request $request, Project $project): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));

        return Api::ok([
            'project' => ['text_review' => $project->text_review?->value, 'raw' => $project->desc_raw, 'suggested' => $project->desc_suggested],
            'units' => $project->units()->get()->map(fn (ProjectUnit $u) => [
                'id' => $u->id, 'model_name' => $u->model_name, 'text_review' => $u->text_review?->value, 'raw' => $u->desc_raw, 'suggested' => $u->desc_suggested,
            ]),
        ]);
    }

    public function decide(Request $request, Project $project, DecideProjectTexts $decide): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));
        $data = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'text' => ['required_if:decision,edit', 'nullable', 'string', 'min:10', 'max:4000'],
        ]);
        $decide->project($project, ReviewDecision::from($data['decision']), $data['text'] ?? null);

        return Api::ok(ProjectResource::detail($project->load(self::WITH)));
    }

    public function publish(Request $request, Project $project, PublishProject $publish): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));
        $publish($project);

        return Api::ok(ProjectResource::detail($project->load(self::WITH)));
    }

    public function pause(Request $request, Project $project, ChangeProjectStatus $status): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));
        if (! $project->status->canBePausedByAdvertiser()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $project->status->value, 'to' => ListingStatus::Paused->value]);
        }
        $status($project, ListingStatus::Paused, Actor::customer($request->user('api')));

        return Api::ok(ProjectResource::detail($project->load(self::WITH)));
    }

    public function resume(Request $request, Project $project, PublishProject $publish): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));
        $publish->resume($project);

        return Api::ok(ProjectResource::detail($project->load(self::WITH)));
    }

    /** What the public sees of a project's unit models (ProjectUnit::isAdvertisable). */
    private function publicUnits(Project $project): void
    {
        $project->setRelation('units', $project->units->filter(fn (ProjectUnit $unit) => $unit->isAdvertisable($project))->values());
    }

    private function isMine(Request $request, Project $project): bool
    {
        $viewer = $request->user('api');

        return $viewer instanceof Customer && $viewer->id === $project->developer_id;
    }

    private function visible(Request $request, Project $project): void
    {
        $viewer = $request->user('api');
        if (! PublicView::project($project, $viewer instanceof Customer ? $viewer : null)) {
            throw ApiException::of(ErrorCode::NotFound);
        }
    }
}
