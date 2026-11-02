<?php

namespace App\Http\Resources;

use App\Models\BuyerPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BuyerPreference */
class PreferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose->value,
            'city' => $this->city,
            'neighborhoods' => $this->neighborhoods,
            'property_types' => $this->property_types,
            'budget_min' => $this->budget_min === null ? null : (float) $this->budget_min,
            'budget_max' => $this->budget_max === null ? null : (float) $this->budget_max,
            'bedrooms' => $this->bedrooms,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'share_in_reports' => $this->share_in_reports,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
