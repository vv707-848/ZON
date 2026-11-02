<?php

namespace App\Http\Requests\Api;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use Illuminate\Validation\Rule;

/** The seeker's wish (_WishSetup of login_screen.dart): city, neighbourhoods, types (none = every type), budget, notes. */
class PreferenceRequest extends ApiRequest
{
    protected array $numeric = ['budget_min', 'budget_max', 'bedrooms'];

    protected array $ranges = [['budget_min', 'budget_max']];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $need = $this->isMethod('POST') ? 'required' : 'sometimes';
        $city = $this->input('city', $this->route('preference')?->city);

        return [
            'purpose' => ['sometimes', Rule::enum(Purpose::class)],
            'city' => [$need, 'string', new KnownCity],
            'neighborhoods' => [$need, 'array', 'min:1', 'max:10', new KnownNeighborhood(is_string($city) ? $city : null)],
            'neighborhoods.*' => ['string', 'distinct'],
            'property_types' => ['sometimes', 'array', 'max:10'],
            'property_types.*' => [Rule::enum(PropertyType::class), 'distinct'],
            'budget_min' => ['nullable', 'numeric', 'gte:0'],
            'budget_max' => ['nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'between:0,50'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'share_in_reports' => ['sometimes', 'boolean'],
        ];
    }
}
