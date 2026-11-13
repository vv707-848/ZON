<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Project;
use App\Support\Favorites;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project card / page. The starting price is computed from the available unit models (never stored).
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public bool $detailed = false;

    public static function detail(Project $project): self
    {
        $resource = new self($project);
        $resource->detailed = true;

        return $resource;
    }

    /** @return array<int|string, mixed> mergeWhen() parts are merged when resolved */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('api');
        $mine = $viewer instanceof Customer && $viewer->id === $this->developer_id;
        $units = $this->relationLoaded('units') ? $this->units : collect();
        $units->each(fn ($unit) => $unit->setRelation('project', $this->resource));   // each unit knows it is its developer's (his own texts)
        $available = $units->where('available_count', '>', 0);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_favorite' => Favorites::of($request, 'project', $this->id),
            'title' => $this->title,
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'property_type' => $this->property_type->value,
            'delivery' => $this->delivery->value,
            'land_use' => $this->land_use?->value,
            'total_units' => $this->total_units,
            'available_units' => (int) $available->sum('available_count'),
            'models_count' => $units->count(),
            'start_price' => $available->isEmpty() ? null : (float) $available->min('price'),
            'description' => $this->publicDescription(),
            'featured' => $this->featured_until?->isFuture() ?? false,
            'featured_until' => $this->featured_until?->isFuture() ? $this->featured_until->toIso8601String() : null,
            'cover_url' => $this->relationLoaded('media') ? $this->media->first()?->url('card') : null,
            'published_at' => $this->published_at?->toIso8601String(),
            'share_url' => rtrim((string) config('zon.deep_link_base'), '/')."/projects/{$this->id}",
            'developer' => $this->relationLoaded('developer') ? new AdvertiserResource($this->developer) : null,
            $this->mergeWhen($this->detailed, fn () => [
                'units' => UnitResource::collection($units),
                'photos' => MediaResource::collection($this->media),
                'lat' => $this->lat === null ? null : (float) $this->lat,
                'lng' => $this->lng === null ? null : (float) $this->lng,
                'maps_link' => $this->maps_link,
                'phones' => $viewer instanceof Customer ? array_map([Phone::class, 'local'], $this->phones ?: [$this->developer->phone]) : null,
            ]),
            $this->mergeWhen($mine, fn () => [
                'desc_raw' => $this->desc_raw,
                'desc_suggested' => $this->desc_suggested,
                'text_review' => $this->text_review?->value,
            ]),
        ];
    }
}
