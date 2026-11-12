<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyBoost;
use App\Actions\Requests\CreateSearchRequest;
use App\Enums\AreaRequestsAccess;
use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Requests\SearchRequestForm;
use App\Http\Resources\SearchRequestResource;
use App\Indexing\IndexSync;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\SearchRequest;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Ownership;
use App\Support\PublicView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search requests: the public board, mine, the broker's area, edit, make urgent, close, «أضف إلى إدارة العملاء».
 * The board orders as the app's «الطلبات» does: the newest or the oldest with the urgent first, the urgent only, or
 * the lowest budget (one without a budget last).
 */
class RequestController extends Controller
{
    public const SORTS = ['new', 'old', 'urgent', 'cheap'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', 'in:seeker,broker,owner,developer'],
            'purpose' => ['nullable', 'in:sale,rent'],
            'city' => ['nullable', 'string'],
            'neighborhood' => ['nullable', 'string'],
            'property_type' => ['nullable', 'string'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'per_page' => ['nullable', 'integer', 'between:5,50'],
        ]);

        return Api::page($this->board($request, $filters)->paginate((int) ($filters['per_page'] ?? 20)), SearchRequestResource::class);
    }

    public function show(Request $request, SearchRequest $searchRequest): JsonResponse
    {
        $viewer = $request->user('api');
        $viewer = $viewer instanceof Customer ? $viewer : null;
        if (! PublicView::request($searchRequest, $viewer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        return Api::ok(new SearchRequestResource($searchRequest->load('customer')));
    }

    public function mine(Request $request): JsonResponse
    {
        return Api::page($request->user('api')->searchRequests()->with('customer')->latest()->paginate(20), SearchRequestResource::class);
    }

    /** The broker's city, urgent first — what his plan lets him see of the authors is in each item. */
    public function area(Request $request): JsonResponse
    {
        $broker = $request->user('api');
        $page = $this->board($request, ['city' => $broker->city, 'sort' => 'new'])->where('customer_id', '!=', $broker->id)->paginate(20);

        return Api::page($page, SearchRequestResource::class, ['access' => SearchRequestResource::access($broker)->value]);
    }

    public function store(SearchRequestForm $request, CreateSearchRequest $create): JsonResponse
    {
        [$created, $urgent] = $create($request->customer(), $request->validated());

        return Api::created(new SearchRequestResource($created->load('customer')), $urgent === null ? [] : ['urgent' => $urgent]);
    }

    /** The whole form again (its «عاجل» is not changed here: see urgent()); a closed request stays closed. */
    public function update(SearchRequestForm $request, SearchRequest $searchRequest, IndexSync $index): JsonResponse
    {
        Ownership::assert($searchRequest, $request->customer());
        if ($searchRequest->status !== RequestStatus::Published) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
        $form = $request->validated();
        unset($form['urgent']);
        $searchRequest->fill([
            'budget_min' => null, 'budget_max' => null, 'area_min' => null, 'area_max' => null, 'rooms_min' => null, 'rooms_max' => null, 'description' => null,
            ...$form,
        ])->save();
        $index->request($searchRequest);

        return Api::ok(new SearchRequestResource($searchRequest->load('customer')));
    }

    /** «اجعله طلبًا عاجلًا»: from the plan's balance; without balance → 402 with the price (the app then pays). */
    public function urgent(Request $request, SearchRequest $searchRequest, ApplyBoost $boost): JsonResponse
    {
        $customer = $request->user('api');
        Ownership::assert($searchRequest, $customer);
        if ($searchRequest->status !== RequestStatus::Published) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
        $boost($customer, $searchRequest, BoostKind::UrgentRequest);

        return Api::ok(new SearchRequestResource($searchRequest->refresh()->load('customer')));
    }

    public function close(Request $request, SearchRequest $searchRequest, IndexSync $index): JsonResponse
    {
        Ownership::assert($searchRequest, $request->user('api'));
        $searchRequest->forceFill(['status' => RequestStatus::Closed, 'closed_at' => now()])->save();
        $index->request($searchRequest);

        return Api::ok(new SearchRequestResource($searchRequest->load('customer')));
    }

    /** Needs the full area-requests service; the client is created without the author's phone (talk in the chat). */
    public function toClient(Request $request, SearchRequest $searchRequest): JsonResponse
    {
        $broker = $request->user('api');
        if ($searchRequest->status !== RequestStatus::Published || $searchRequest->customer_id === $broker->id || Blocks::hides($broker, $searchRequest->customer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        if (SearchRequestResource::access($broker) !== AreaRequestsAccess::Full) {
            throw ApiException::of(ErrorCode::PaymentRequired);
        }
        $client = CrmClient::query()->firstOrCreate(['broker_id' => $broker->id, 'source_request_id' => $searchRequest->id], [
            'name' => $searchRequest->customer->name,
            'purpose' => $searchRequest->purpose,
            'city' => $searchRequest->city,
            'neighborhoods' => $searchRequest->neighborhoods,
            'property_types' => $searchRequest->property_types,
            'budget_min' => $searchRequest->budget_min,
            'budget_max' => $searchRequest->budget_max,
            'area_min' => $searchRequest->area_min,
            'rooms_min' => $searchRequest->rooms_min,
            'description' => $searchRequest->description,
        ]);

        return Api::created(['client_id' => $client->id]);
    }

    /**
     * @param  array<string, ?string>  $filters
     * @return Builder<SearchRequest>
     */
    private function board(Request $request, array $filters): Builder
    {
        $viewer = $request->user('api');

        return SearchRequest::query()->where('status', RequestStatus::Published)->with('customer')
            ->whereNotIn('customer_id', Blocks::hiddenFor($viewer instanceof Customer ? $viewer : null))
            ->when($filters['role'] ?? null, fn (Builder $q, $v) => $q->whereHas('customer', fn ($c) => $c->where('role', $v)))
            ->when($filters['purpose'] ?? null, fn (Builder $q, $v) => $q->where('purpose', $v))
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->whereJsonContains('neighborhoods', $v))
            ->when($filters['property_type'] ?? null, fn (Builder $q, $v) => $q->whereJsonContains('property_types', $v))
            ->tap(fn (Builder $q) => match ($filters['sort'] ?? 'new') {
                'urgent' => $q->where('urgent', true)->latest('id'),
                'old' => $q->orderByDesc('urgent')->oldest('id'),
                'cheap' => $q->orderByRaw('COALESCE(budget_min, budget_max) IS NULL')->orderByRaw('COALESCE(budget_min, budget_max)')->latest('id'),
                default => $q->orderByDesc('urgent')->latest('id'),
            });
    }
}
