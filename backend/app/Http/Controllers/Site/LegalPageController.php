<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\Site\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/** The platform's policies, as published from «صفحات السياسات» (the same text the app shows), with their version. */
class LegalPageController extends Controller
{
    public function index(): View
    {
        return view('site.legal.index', ['policies' => Site::policies()]);
    }

    public function show(string $slug): View
    {
        $page = LegalPage::query()->where('slug', $slug)->whereNotNull('published_at')->firstOrFail();

        // Markdown written in the panel; raw HTML and unsafe links are dropped (the text is staff's, the page is public).
        $html = Str::markdown($page->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return view('site.legal.show', ['page' => $page, 'html' => $html]);
    }
}
