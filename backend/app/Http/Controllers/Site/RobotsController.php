<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** robots.txt: the website opens its pages to search engines (with the sitemap); every other host (api.zon.sa) closes. */
class RobotsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lines = $request->getHost() === Site::host()
            ? ['User-agent: *', 'Disallow: /admin', 'Disallow: /api/', 'Disallow: /pay/', 'Disallow: /account/', 'Disallow: /complaints/', '', 'Sitemap: '.Site::url('sitemap.xml')]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8'])->setPublic()->setMaxAge(3600);
    }
}
