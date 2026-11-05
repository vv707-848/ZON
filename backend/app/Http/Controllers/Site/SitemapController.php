<?php

namespace App\Http\Controllers\Site;

use App\Enums\CustomerRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\Blocks;
use App\Support\Site\Browse;
use App\Support\Site\Site;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml for search engines: an index of parts — the fixed pages, the listings by ten thousand (the protocol
 * allows 50,000 per file), the projects and their units, the advertisers' pages. Each part is kept an hour, so
 * crawlers do not query the database on every visit. Requests are left out on purpose (noindex).
 */
class SitemapController extends Controller
{
    public const PER_FILE = 10_000;

    private const TTL = 3600;

    public function index(): Response
    {
        $xml = Cache::remember('site.sitemap.index', self::TTL, function () {
            $files = max(1, (int) ceil(Browse::listings()->count() / self::PER_FILE));
            $parts = ['pages', ...array_map(fn (int $n) => "listings-{$n}", range(1, $files)), 'projects', 'users'];

            return $this->xml('sitemapindex', array_map(fn (string $part) => '<sitemap><loc>'.e(Site::url("sitemaps/{$part}.xml")).'</loc></sitemap>', $parts));
        });

        return $this->respond($xml);
    }

    public function part(string $part): Response
    {
        $xml = Cache::remember("site.sitemap.{$part}", self::TTL, fn () => $this->xml('urlset', match (true) {
            $part === 'pages' => $this->pages(),
            $part === 'projects' => $this->projects(),
            $part === 'users' => $this->users(),
            default => $this->listings((int) substr($part, strlen('listings-'))),
        }));

        return $this->respond($xml);
    }

    /** @return list<string> the home, the feed, the plans, complaints, a page per city and per city and type with listings, the policies */
    private function pages(): array
    {
        $urls = [$this->url(Site::url()), $this->url(Browse::url([])), $this->url(Site::url('packages')), $this->url(Site::url('complaints'))];
        $places = Browse::listings()->reorder()->toBase()->selectRaw('city, property_type')->groupBy('city', 'property_type')->get();
        foreach ($places->pluck('city')->unique() as $city) {
            $urls[] = $this->url(Browse::url(['city' => (string) $city]));
        }
        foreach ($places as $place) {
            $urls[] = $this->url(Browse::url(['city' => (string) $place->city, 'type' => (string) $place->property_type]));
        }
        foreach (array_keys(Site::policies()) as $slug) {
            $urls[] = $this->url(Site::url("legal/{$slug}"));
        }

        return $urls;
    }

    /** @return list<string> */
    private function listings(int $file): array
    {
        abort_if($file < 1, 404);

        return Browse::listings()->reorder('id')->toBase()->forPage($file, self::PER_FILE)->get(['listings.id', 'listings.updated_at'])
            ->map(fn (object $row) => $this->url(Site::url("listings/{$row->id}"), (string) $row->updated_at))->all();
    }

    /** @return list<string> projects and the units a guest may open */
    private function projects(): array
    {
        $urls = [];
        Browse::projects()->reorder('id')->chunk(500, function ($projects) use (&$urls) {
            foreach ($projects as $project) {
                assert($project instanceof Project);
                $urls[] = $this->url(Site::url("projects/{$project->id}"), (string) $project->updated_at);
                foreach ($project->units->filter(fn (ProjectUnit $unit) => $unit->isAdvertisable($project)) as $unit) {
                    $urls[] = $this->url(Site::url("projects/{$project->id}/units/{$unit->id}"), (string) $unit->updated_at);
                }
            }
        });

        return $urls;
    }

    /** @return list<string> advertisers with something live */
    private function users(): array
    {
        return Customer::query()->whereIn('role', [CustomerRole::Broker, CustomerRole::Owner, CustomerRole::Developer])
            ->whereNull('anonymized_at')->where('status', 'active')->whereNotIn('id', Blocks::hiddenFor(null))
            ->where(fn ($q) => $q->whereHas('listings', fn ($l) => $l->live())->orWhereHas('projects', fn ($p) => $p->published()))
            ->orderBy('id')->limit(50_000)->get(['id', 'username', 'updated_at'])
            ->map(fn (Customer $c) => $this->url(Site::url('users/'.rawurlencode((string) $c->username)), (string) $c->updated_at))->all();
    }

    private function url(string $loc, ?string $modified = null): string
    {
        $lastmod = $modified ? '<lastmod>'.date('Y-m-d', (int) strtotime($modified)).'</lastmod>' : '';

        return '<url><loc>'.e($loc).'</loc>'.$lastmod.'</url>';
    }

    /** @param list<string> $items */
    private function xml(string $root, array $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode("\n", $items).'</'.$root.'>';
    }

    private function respond(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8'])->setPublic()->setMaxAge(self::TTL);
    }
}
