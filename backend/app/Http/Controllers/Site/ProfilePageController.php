<?php

namespace App\Http\Controllers\Site;

use App\Enums\CustomerRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Follow;
use App\Models\Rating;
use App\Support\PublicView;
use App\Support\Sandbox;
use App\Support\Site\Browse;
use Illuminate\Contracts\View\View;

/** /users/{name}: an advertiser's public page (as the app's profile shows a guest) and his live listings or projects. */
class ProfilePageController extends Controller
{
    public function __invoke(Customer $customer): View
    {
        abort_unless(PublicView::profile($customer, null), 404);
        $sandbox = Sandbox::hiddenFrom(null);           // a tester's stars and follows count for testers only
        $ratings = Rating::query()->visible()->where('rated_id', $customer->id)->whereNotIn('rater_id', $sandbox);
        $listings = Browse::apply(Browse::listings()->where('customer_id', $customer->id), [])->paginate(Browse::PER_PAGE);
        $projects = $customer->role === CustomerRole::Developer
            ? Browse::projects()->where('developer_id', $customer->id)->latest('published_at')->get() : collect();

        return view('site.profile', [
            'customer' => $customer,
            'rating' => ['average' => round((float) (clone $ratings)->avg('stars'), 1), 'count' => (clone $ratings)->count()],
            'followers' => Follow::query()->where('followed_id', $customer->id)->whereNotIn('follower_id', $sandbox)->count(),
            'listings' => $listings,
            'projects' => $projects,
        ]);
    }
}
