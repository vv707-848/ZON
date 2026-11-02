<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\ProjectUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit model of a project (unit_row.dart, unit_screen.dart).
 *
 * @mixin ProjectUnit
 */
class UnitResource extends JsonResource
{
    /** @return array<int|string, mixed> mergeWhen() parts are merged when resolved */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('api');
        $mine = $viewer instanceof Customer && $this->relationLoaded('project') && $viewer->id === $this->project->developer_id;
        $number = fn ($v) => $v === null ? null : (float) $v;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'model_name' => $this->model_name,
            'property_type' => $this->property_type->value,
            'area' => $number($this->area),
            'area_land' => $number($this->area_land),
            'area_built' => $number($this->area_built),
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'halls' => $this->halls,
            'land_use' => $this->land_use?->value,
            'price' => (float) $this->price,
            'available_count' => $this->available_count,
            'payment_method' => $this->payment_method,
            'description' => $this->publicDescription(),
            'licence' => $this->relationLoaded('licence') && $this->licence !== null
                ? ['number' => $this->licence->licence_number, 'qr_url' => $this->licence->qr_url, 'verified' => $this->licence->isValid()] : null,
            'photos' => $this->relationLoaded('media') ? MediaResource::collection($this->media) : [],
            $this->mergeWhen($mine, fn () => [
                'desc_raw' => $this->desc_raw,
                'desc_suggested' => $this->desc_suggested,
                'text_review' => $this->text_review?->value,
            ]),
        ];
    }
}
