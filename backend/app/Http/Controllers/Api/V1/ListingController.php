<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyBoost;
use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\DecideListingText;
use App\Actions\Listings\MarkListingSold;
use App\Actions\Listings\UpdateListing;
use App\Actions\Listings\VerifyAdLicence;
use App\Enums\BoostKind;
use App\Enums\ErrorCode;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Enums\ReviewDecision;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Listings\ListingRequest;
use App\Http\Resources\ListingResource;
use App\Indexing\IndexSync;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\Favorite;
use App\Models\Listing;
use App\Support\Actor;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Ownership;
use App\Support\PublicView;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Listings: the public feed and pages, and the advertiser's own (create · edit · wording · pause · sold · boost). */
class ListingController extends Controller
{
    /** The home feed with the app's lens: city · neighbourhood · type · purpose · price · source · sort. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'city' => ['nullable', 'string'],
            'neighborhood' => ['nullable', 'string'],
            'neighborhoods' => ['nullable', 'array'],
            'property_type' => ['nullable', 'string'],
            'property_types' => ['nullable', 'array'],
            'purpose' => ['nullable', 'in:sale,rent'],
            'price_min' => ['nullable', 'numeric', 'gte:0'],
            'price_max' => ['nullable', 'numeric', 'gte:0'],
            'source' => ['nullable', 'in:broker,owner,developer'],
            'q' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:new,price_asc,price_desc'],
            'per_page' => ['nullable', 'integer', 'between:5,50'],
        ]);
        $viewer = $request->user('api');

        $query = Listing::query()->live()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])
            ->whereNotIn('customer_id', Blocks::hiddenFor($viewer instanceof Customer ? $viewer : null))
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->where('neighborhood', $v))
            ->when($filters['neighborhoods'] ?? null, fn (Builder $q, $v) => $q->whereIn('neighborhood', $v))
            ->when($filters['property_type'] ?? null, fn (Builder $q, $v) => $q->where('property_type', $v))
            ->when($filters['property_types'] ?? null, fn (Builder $q, $v) => $q->whereIn('property_type', $v))
            ->when($filters['purpose'] ?? null, fn (Builder $q, $v) => $q->where('purpose', $v))
            ->when(isset($filters['price_min']), fn (Builder $q) => $q->where('price', '>=', $filters['price_min']))
            ->when(isset($filters['price_max']), fn (Builder $q) => $q->where('price', '<=', $filters['price_max']))
            ->when($filters['source'] ?? null, fn (Builder $q, $v) => $q->whereHas('customer', fn ($c) => $c->where('role', $v)))
            ->when($filters['q'] ?? null, fn (Builder $q, $v) => $q->where('title', 'like', '%'.addcslashes($v, '%_\\').'%'));

        match ($filters['sort'] ?? 'new') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default => $query->orderByRaw('featured_until IS NOT NULL AND featured_until > ? DESC', [now()])->orderByDesc('published_at'),
        };
        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $this->markFavorites($request, $page->getCollection()->modelKeys());

        return Api::page($page, ListingResource::class);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $viewer = $request->user('api');
        $viewer = $viewer instanceof Customer ? $viewer : null;
        $mine = $viewer !== null && $viewer->id === $listing->customer_id;
        if (! PublicView::listing($listing, $viewer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        if (! $mine && ! $viewer?->is_tester) {
            $listing->increment('views_count');         // a tester's look is not a view
        }
        $listing->load(['customer', 'media', 'licence']);
        $this->markFavorites($request, [$listing->id]);

        return Api::ok(ListingResource::detail($listing));
    }

    /** «عروضي»: every status but the deleted ones. */
    public function mine(Request $request): JsonResponse
    {
        $page = $request->user('api')->listings()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])
            ->when($request->query('status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->latest('updated_at')->paginate(20);

        return Api::page($page, ListingResource::class);
    }

    public function store(ListingRequest $request, CreateListing $create): JsonResponse
    {
        $listing = $create(
            $request->customer(),
            $request->columns(),
            array_map('intval', $request->input('media_ids', [])),
            $request->integer('cover_id') ?: null,
            $request->input('licence_number'),
            $request->integer('owner_case_id') ?: null,
        );

        return Api::created(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    public function update(ListingRequest $request, Listing $listing, UpdateListing $update): JsonResponse
    {
        $this->own($request, $listing);
        if (in_array($listing->status, [ListingStatus::Sold, ListingStatus::Hidden, ListingStatus::Rejected], true)) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
        $update($listing, Actor::customer($request->customer()), $request->columns(),
            $request->has('media_ids') ? array_map('intval', $request->input('media_ids', [])) : null, $request->integer('cover_id') ?: null);

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    public function destroy(Request $request, Listing $listing, IndexSync $index): JsonResponse
    {
        $this->own($request, $listing);
        if ($listing->status === ListingStatus::Sold) {
            throw ApiException::of(ErrorCode::InvalidTransition);     // a sale stays on record
        }
        $listing->delete();
        $index->listing($listing);

        return Api::ok();
    }

    /** The wording waiting for the advertiser: his text, the suggestion, and when it publishes by itself. */
    public function review(Request $request, Listing $listing): JsonResponse
    {
        $this->own($request, $listing);

        return Api::ok([
            'status' => $listing->status->value,
            'text_review' => $listing->text_review?->value,
            'raw' => $listing->desc_raw,
            'suggested' => $listing->desc_suggested,
            'auto_publish_at' => $listing->status === ListingStatus::PendingReview
                ? $listing->updated_at?->addHours(Settings::int(Setting::ReviewAutoPublishHours))->toIso8601String() : null,
        ]);
    }

    public function decide(Request $request, Listing $listing, DecideListingText $decide): JsonResponse
    {
        $this->own($request, $listing);
        $data = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'text' => ['required_if:decision,edit', 'nullable', 'string', 'min:10', 'max:3000'],
        ]);
        $decide($listing, ReviewDecision::from($data['decision']), $data['text'] ?? null);

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    public function pause(Request $request, Listing $listing, ChangeListingStatus $status): JsonResponse
    {
        $this->own($request, $listing);
        $data = $request->validate(['until' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(91)->toDateString()]]);
        if (! $listing->status->canBePausedByAdvertiser()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $listing->status->value, 'to' => ListingStatus::Paused->value]);
        }
        $status($listing, ListingStatus::Paused, Actor::customer($request->user('api')), ['paused_until' => $data['until'] ?? null]);

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    public function resume(Request $request, Listing $listing, ChangeListingStatus $status): JsonResponse
    {
        $this->own($request, $listing);
        if (! $listing->status->canBeResumedByAdvertiser()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $listing->status->value, 'to' => ListingStatus::Published->value]);
        }
        $status($listing, ListingStatus::Published, Actor::customer($request->user('api')));

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    /**
     * «تحقق»: a broker's licence asked of REGA before he fills his listing, so the app says «تم التحقق» only when
     * it is. Nothing is saved; the listing is checked again when it is.
     */
    public function checkLicence(Request $request, VerifyAdLicence $verify): JsonResponse
    {
        $number = (string) $request->validate(['licence_number' => ['required', 'regex:/^\d{6,12}$/']])['licence_number'];
        $check = $verify($number);

        return Api::ok(['licence_number' => $number, 'expires_at' => $check->expiry()->toDateString(), 'qr_url' => $check->qrUrl]);
    }

    /**
     * «تحديث الرخصة»: the advertiser renewed at REGA (the same number or a new one). REGA is asked again, the
     * listing gets the licence as a new row (the old one stays on record), and a listing that left because its
     * licence ended comes back.
     */
    public function renewLicence(Request $request, Listing $listing, VerifyAdLicence $verify, ChangeListingStatus $status): JsonResponse
    {
        $this->own($request, $listing);
        if (in_array($listing->status, [ListingStatus::Sold, ListingStatus::Hidden, ListingStatus::Rejected], true)) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $listing->status->value]);
        }
        $number = (string) ($request->validate(['licence_number' => ['nullable', 'regex:/^\d{6,12}$/']])['licence_number']
            ?? $listing->licence?->licence_number);
        $check = $verify($number);

        DB::transaction(function () use ($request, $listing, $number, $check, $status) {
            AdLicense::query()->create([
                'listing_id' => $listing->id, 'customer_id' => $listing->customer_id, 'licence_number' => $number,
                'qr_url' => $check->qrUrl, 'source' => LicenceSource::Entered, 'status' => LicenceStatus::Valid,
                'verified_at' => now(), 'expires_at' => $check->expiry(),
            ]);
            if ($listing->status === ListingStatus::LicenceExpired) {
                $status($listing, ListingStatus::Published, Actor::customer($request->user('api')));
            } else {
                $listing->forceFill(['last_activity_at' => now()])->save();
            }
        });

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    public function sold(Request $request, Listing $listing, MarkListingSold $sold): JsonResponse
    {
        $this->own($request, $listing);
        // an owner's listing is sold at the price he says it sold for: Zon's commission is reckoned on it
        $owners = $listing->ownerCase()->exists();
        $data = $request->validate(['sale_price' => [$owners ? 'required' : 'nullable', 'numeric', 'gt:0', 'decimal:0,2']]);
        $sold($listing, Actor::customer($request->user('api')), isset($data['sale_price']) ? (string) $data['sale_price'] : null);

        return Api::ok(ListingResource::detail($listing->load(['customer', 'media', 'licence'])));
    }

    /** From the plan's balance; without balance → 402 with the price (the app then opens checkout). */
    public function boost(Request $request, Listing $listing, ApplyBoost $boost): JsonResponse
    {
        $this->own($request, $listing);
        $data = $request->validate(['kind' => ['required', 'in:featured,urgent_listing']]);
        $usage = $boost($request->user('api'), $listing, BoostKind::from($data['kind']));

        return Api::ok(['kind' => $usage->kind->value, 'via' => $usage->via->value, 'active_until' => $usage->active_until?->toIso8601String()]);
    }

    private function own(Request $request, Listing $listing): void
    {
        Ownership::assert($listing, $request->user('api'));
    }

    /** @param list<int> $ids */
    private function markFavorites(Request $request, array $ids): void
    {
        $viewer = $request->user('api');
        if ($viewer instanceof Customer && $ids !== []) {
            $request->attributes->set('favorite_listing_ids', Favorite::query()->where('customer_id', $viewer->id)
                ->where('favoritable_type', 'listing')->whereIn('favoritable_id', $ids)->pluck('favoritable_id')->map(fn ($id) => (int) $id)->all());
        }
    }
}
