<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\MarketBrief;
use Illuminate\Http\Response;

/**
 * A market brief sold to a developer, on the website: by its link only (40 random characters), until it expires; never
 * indexed nor kept by a cache. A closed or unknown link answers the same 404, so a guess learns nothing.
 */
class MarketBriefController extends Controller
{
    public function __invoke(string $token): Response
    {
        $brief = MarketBrief::query()->where('token', $token)->first();
        abort_unless($brief !== null && $brief->isOpen(), 404);
        $brief->increment('views_count');

        return response()->view('site.briefs.show', ['brief' => $brief])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
