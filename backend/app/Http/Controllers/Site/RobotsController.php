<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt: the website opens its pages to search engines (with the sitemap); every other host (api.zon.sa) closes.
 * The account stays closed except its deletion page, which people search for («حذف حساب زون»): the longest
 * matching rule wins (RFC 9309), so `Allow: /account/delete` opens that one page under `Disallow: /account`.
 */
class RobotsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lines = $request->getHost() === Site::host()
            ? ['User-agent: *', 'Disallow: /admin', 'Disallow: /api/', 'Disallow: /pay/', 'Disallow: /subscribe/', 'Disallow: /account',
                'Allow: /account/delete', 'Disallow: /login', 'Disallow: /password/', 'Disallow: /complaints/', '', 'Sitemap: '.Site::url('sitemap.xml')]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8'])->setPublic()->setMaxAge(3600);
    }
}
