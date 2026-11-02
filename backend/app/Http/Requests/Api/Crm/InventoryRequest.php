<?php

namespace App\Http\Requests\Api\Crm;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Http\Requests\Api\ApiRequest;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use Illuminate\Validation\Rule;

/** A private stock item (inventory_view.dart) — never public until «نشر كإعلان». */
class InventoryRequest extends ApiRequest
{
    protected array $numeric = ['price', 'area_land', 'area_built', 'bedrooms', 'bathrooms'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $need = $this->isMethod('POST') ? 'required' : 'sometimes';
        $city = $this->input('city', $this->route('item')?->city);

        return [
            'purpose' => [$need, Rule::enum(Purpose::class)],
            'property_type' => [$need, Rule::enum(PropertyType::class)],
            'title' => [$need, 'string', 'max:160'],
            'city' => [$need, 'string', new KnownCity],
            'neighborhood' => [$need, 'string', new KnownNeighborhood(is_string($city) ? $city : null)],
            'price' => ['nullable', 'numeric', 'gt:0'],
            'area_land' => ['nullable', 'numeric', 'gt:0'],
            'area_built' => ['nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'between:0,50'],
            'bathrooms' => ['nullable', 'integer', 'between:0,50'],
            'description' => ['nullable', 'string', 'max:3000'],
            'media_ids' => ['sometimes', 'array', 'max:12'],
            'media_ids.*' => ['integer', 'distinct'],
        ];
    }
}
