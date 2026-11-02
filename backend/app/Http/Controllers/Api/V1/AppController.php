<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Delivery;
use App\Enums\FloorLevel;
use App\Enums\LandUse;
use App\Enums\NoteCategory;
use App\Enums\NoticePref;
use App\Enums\Occupancy;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Enums\ReportReason;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Models\AppConfig;
use App\Models\City;
use App\Models\LegalPage;
use App\Support\Api;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the app reads before anything else: versions and maintenance, the catalog, the policy pages. */
class AppController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $platform = strtolower((string) ($request->header('X-App-Platform') ?? $request->query('platform')));
        $config = AppConfig::for($platform);

        return Api::ok([
            'platform' => $config === null ? null : [
                'min_version' => $config->min_version,
                'latest_version' => $config->latest_version,
                'store_url' => $config->store_url,
                'maintenance' => $config->inMaintenance()
                    ? ['message' => $config->maintenance_message, 'until' => $config->maintenance_until?->toIso8601String()]
                    : null,
            ],
            'deep_link_base' => config('zon.deep_link_base'),
            'catalog_version' => substr(md5((string) json_encode(City::catalog())), 0, 12),
            'photos_max' => Settings::int(Setting::ListingPhotosMax),
            'photo_max_kb' => (int) config('zon.media.max_kb'),
            'chat_max_chars' => Settings::int(Setting::ChatMaxChars),
        ]);
    }

    /** Everything the forms and filters list, so the app has no hard-coded catalog any more. */
    public function catalog(): JsonResponse
    {
        return Api::ok([
            'cities' => collect(City::catalog())->map(fn (array $hoods, string $city) => ['name' => $city, 'neighborhoods' => $hoods])->values(),
            'property_types' => array_map(fn (PropertyType $type) => [
                'key' => $type->value,
                'label' => $type->label(),
                'plural' => $type->plural(),
                'fields' => $type->fields(),
                'for_projects' => $type->forProjects(),
                'for_units' => $type->forUnits(),
            ], PropertyType::cases()),
            'retired_types' => PropertyType::RETIRED,
            'purposes' => Purpose::options(),
            'land_uses' => LandUse::options(),
            'floor_levels' => FloorLevel::options(),
            'occupancy' => Occupancy::options(),
            'delivery' => Delivery::options(),
            'note_categories' => NoteCategory::options(),
            'task_priorities' => TaskPriority::options(),
            'report_reasons' => ReportReason::options(),
            'notification_prefs' => NoticePref::options(),
        ]);
    }

    public function legal(string $slug): JsonResponse
    {
        $page = LegalPage::query()->where('slug', $slug)->whereNotNull('published_at')->firstOrFail();

        return Api::ok($page->only(['slug', 'title', 'body', 'version']) + ['published_at' => $page->published_at?->toIso8601String()]);
    }
}
