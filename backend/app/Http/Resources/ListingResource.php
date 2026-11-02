<?php

namespace App\Http\Resources;

use App\Http\Requests\Api\Listings\ListingRequest;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing for the app. Lists get the card (cover only); a detail page gets every photo, the licence and the
 * phones (signed-in viewers only). The advertiser also gets his texts (raw · suggested), review state and history.
 *
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    public bool $detailed = false;

    public static function detail(Listing $listing): self
    {
        $resource = new self($listing);
        $resource->detailed = true;

        return $resource;
    }

    /** @return array<int|string, mixed> mergeWhen() parts are merged when resolved */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('api');
        $mine = $viewer instanceof Customer && $viewer->id === $this->customer_id;
        $cover = $this->relationLoaded('media') ? $this->media->first() : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'purpose' => $this->purpose->value,
            'property_type' => $this->property_type->value,
            'title' => $this->title,
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'price' => (float) $this->price,
            'negotiable' => $this->negotiable,
            ...$this->specs(),
            'has_deed' => $this->has_deed,
            'bank_accepted' => $this->bank_accepted,
            'description' => $this->publicDescription(),
            'features' => $this->features ?? [],
            'featured' => $this->isFeatured(),
            'featured_until' => $this->featured_until?->toIso8601String(),
            'urgent' => $this->isUrgent(),
            'urgent_until' => $this->urgent_until?->toIso8601String(),
            'cover_url' => $cover?->url(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'views_count' => $this->views_count,
            'is_favorite' => in_array($this->id, (array) $request->attributes->get('favorite_listing_ids', []), true),
            'share_url' => rtrim((string) config('zon.deep_link_base'), '/')."/listings/{$this->id}",
            'advertiser' => $this->relationLoaded('customer') ? new AdvertiserResource($this->customer) : null,
            $this->mergeWhen($this->detailed, fn () => [
                'photos' => MediaResource::collection($this->media),
                'lat' => $this->lat === null ? null : (float) $this->lat,
                'lng' => $this->lng === null ? null : (float) $this->lng,
                'maps_link' => $this->maps_link,
                'licence' => $this->licence === null ? null : [
                    'number' => $this->licence->licence_number,
                    'qr_url' => $this->licence->qr_url,
                    'verified' => $this->licence->isValid(),
                ],
                'phones' => $viewer instanceof Customer ? array_map([Phone::class, 'local'], $this->phones ?: [$this->customer->phone]) : null,
            ]),
            $this->mergeWhen($mine, fn () => [
                'desc_raw' => $this->desc_raw,
                'desc_suggested' => $this->desc_suggested,
                'text_review' => $this->text_review?->value,
                'text_reviewed_at' => $this->text_reviewed_at?->toIso8601String(),
                'paused_until' => $this->paused_until?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
            ]),
        ];
    }

    /** @return array<string, mixed> every specification field (null when the type does not use it) */
    private function specs(): array
    {
        $specs = [];
        foreach (ListingRequest::SPEC_FIELDS as $field) {
            $value = $this->getAttribute($field);
            $specs[$field] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                in_array($field, ['area_land', 'area_built', 'annual_income'], true) && $value !== null => (float) $value,
                default => $value,
            };
        }

        return $specs;
    }
}
