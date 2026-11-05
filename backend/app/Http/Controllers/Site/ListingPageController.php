<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Listing;
use App\Support\PublicView;
use App\Support\Site\Browse;
use App\Support\Site\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /listings — the guest feed with filters, each filter set at one address; /listings/{id} — a listing as a guest
 * sees it in the app (no phone: contact is in the app), with its advertising licence and REGA's QR.
 */
class ListingPageController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $filters = Browse::filters($request->query());
        $canonical = Browse::url($filters);
        if ($request->query() != array_map('strval', $filters)) {
            return redirect()->to($canonical, 301);           // one address per page of results
        }

        $page = Browse::apply(Browse::listings(), $filters)
            ->paginate(Browse::PER_PAGE, ['*'], 'page', $filters['page'] ?? 1)
            ->withPath(Site::url('listings'))->appends(array_diff_key($filters, ['page' => true]));
        if (($filters['page'] ?? 1) > 1 && $page->isEmpty()) {
            abort(404);
        }

        return view('site.listings.index', [
            'filters' => $filters,
            'listings' => $page,
            'title' => Browse::title($filters),
            'canonical' => $canonical,
            'cities' => City::catalog(),
        ]);
    }

    public function show(Listing $listing): View
    {
        abort_unless(PublicView::listing($listing, null), 404);
        $listing->load(['customer', 'media', 'licence']);
        $similar = Browse::apply(Browse::listings(), ['city' => $listing->city, 'type' => $listing->property_type->value])
            ->whereKeyNot($listing->id)->limit(4)->get();

        return view('site.listings.show', ['listing' => $listing, 'similar' => $similar]);
    }

    /** A stable address for the cover (link previews keep it; the photo's own link is signed and expires). */
    public function image(Listing $listing): Response
    {
        abort_unless(PublicView::listing($listing, null), 404);
        $cover = $listing->media()->first();
        abort_if($cover === null, 404);

        return redirect()->away($cover->url('large'))->setPublic()->setMaxAge(300);
    }
}
