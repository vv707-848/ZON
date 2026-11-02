<?php

namespace App\Http\Requests\Api\Requests;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Http\Requests\Api\ApiRequest;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use Illuminate\Validation\Rule;

/** «طلب بحث» (request_form.dart): one city, several neighbourhoods and types, ranges, a free text, urgent or not. */
class SearchRequestForm extends ApiRequest
{
    protected array $numeric = ['budget_min', 'budget_max', 'area_min', 'area_max', 'rooms_min', 'rooms_max'];

    protected array $ranges = [['budget_min', 'budget_max'], ['area_min', 'area_max'], ['rooms_min', 'rooms_max']];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::enum(Purpose::class)],
            'city' => ['required', 'string', new KnownCity],
            'neighborhoods' => ['required', 'array', 'min:1', 'max:10', new KnownNeighborhood($this->string('city')->value() ?: null)],
            'neighborhoods.*' => ['string', 'distinct'],
            'property_types' => ['required', 'array', 'min:1', 'max:10'],
            'property_types.*' => [Rule::enum(PropertyType::class), 'distinct'],
            'budget_min' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'budget_max' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'area_min' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'area_max' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'rooms_min' => ['nullable', 'integer', 'between:0,50'],
            'rooms_max' => ['nullable', 'integer', 'between:0,50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'urgent' => ['sometimes', 'boolean'],
        ];
    }
}
