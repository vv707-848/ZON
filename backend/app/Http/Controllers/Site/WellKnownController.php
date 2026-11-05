<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The files that let zon.sa links open the app where it is installed (the app's own paths: /listings/…, /projects/…,
 * /users/…, /requests/…). Served only once the app's identifiers are set; without them, 404 (never a guess).
 *
 * - Apple (developer.apple.com, «Supporting associated domains»): https, no redirect, JSON; Apple's CDN reads it.
 * - Android (developer.android.com, «Verify Android App Links»): the signing certificates' SHA-256.
 */
class WellKnownController extends Controller
{
    /** The app's paths a link may open; the website's own pages (policies, complaints, payments) stay in the browser. */
    public const APP_PATHS = ['/listings/*', '/projects/*', '/users/*', '/requests/*'];

    public function apple(): JsonResponse
    {
        $team = (string) config('services.app_store.team_id');
        abort_if($team === '', 404);

        return response()->json(['applinks' => ['details' => [[
            'appIDs' => [$team.'.'.config('services.app_store.bundle_id')],
            'components' => array_map(fn (string $path) => ['/' => $path], self::APP_PATHS),
        ]]]])->setPublic()->setMaxAge(3600);
    }

    public function android(): JsonResponse
    {
        $fingerprints = (array) config('services.google_play.cert_fingerprints');
        abort_if($fingerprints === [], 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => config('services.google_play.package'), 'sha256_cert_fingerprints' => $fingerprints],
        ]])->setPublic()->setMaxAge(3600);
    }
}
