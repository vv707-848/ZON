<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\Browse;
use Illuminate\Contracts\View\View;

/** The landing page: what Zon does for each party, the latest real listings, and where the app is. */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $latest = Browse::apply(Browse::listings(), [])->limit(6)->get();
        $cities = Browse::listings()->reorder()->toBase()->selectRaw('city, COUNT(*) AS listings')->groupBy('city')
            ->orderByDesc('listings')->limit(8)->pluck('listings', 'city')->map(fn ($n) => (int) $n)->all();

        return view('site.home', ['latest' => $latest, 'cities' => $cities]);
    }
}
