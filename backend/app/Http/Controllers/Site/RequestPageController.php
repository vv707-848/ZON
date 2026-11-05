<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SearchRequest;
use App\Support\PublicView;
use Illuminate\Contracts\View\View;

/**
 * /requests/{id}: a seeker's request as a guest sees it in the app — what is wanted, never who wants it. Shared
 * from the app (a broker sends it on); kept out of search engines (noindex): a wish is not a page to be found.
 */
class RequestPageController extends Controller
{
    public function __invoke(SearchRequest $searchRequest): View
    {
        abort_unless(PublicView::request($searchRequest, null), 404);

        return view('site.request', ['request' => $searchRequest->load('customer')]);
    }
}
