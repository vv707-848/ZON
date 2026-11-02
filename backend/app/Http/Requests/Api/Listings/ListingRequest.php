<?php

namespace App\Http\Requests\Api\Listings;

use App\Enums\FloorLevel;
use App\Enums\LandUse;
use App\Enums\Occupancy;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Http\Requests\Api\ApiRequest;
use App\Models\Listing;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use App\Support\Phone;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Validation\Rule;

/**
 * A listing's form (listing_form.dart). The specification fields a type does not use (PropertyType::fields(),
 * the app's specsForType) must stay empty. On PATCH every field is optional and the type is the listing's own.
 */
class ListingRequest extends ApiRequest
{
    /** Every specification field of every type. */
    public const SPEC_FIELDS = ['area_land', 'area_built', 'bedrooms', 'bathrooms', 'halls', 'age', 'floor_level', 'floor_number', 'floors', 'occupancy', 'land_use', 'annual_income'];

    protected array $numeric = ['price', 'area_land', 'area_built', 'bedrooms', 'bathrooms', 'halls', 'age', 'floor_number', 'floors', 'annual_income', 'licence_number'];

    protected function prepareForValidation(): void
    {
        $type = $this->input('property_type');
        if (is_string($type) && isset(PropertyType::RETIRED[$type])) {
            $this->merge(['property_type' => PropertyType::RETIRED[$type]]);     // app versions before 3.1
        }
        if (is_array($this->input('phones'))) {
            $this->merge(['phones' => array_map(fn ($p) => is_string($p) ? (Phone::normalize($p) ?? $p) : $p, $this->input('phones'))]);
        }
        parent::prepareForValidation();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $editing = $this->record() instanceof Listing;
        $need = $editing ? 'sometimes' : 'required';
        $type = PropertyType::tryFrom((string) $this->input('property_type')) ?? ($editing ? $this->record()->property_type : null);
        $city = $this->input('city', $editing ? $this->record()->city : null);
        $uses = $type?->fields() ?? self::SPEC_FIELDS;
        $spec = fn (string $field, array $rules) => in_array($field, $uses, true) ? ['nullable', ...$rules] : ['prohibited'];

        return [
            'purpose' => [$need, Rule::enum(Purpose::class)],
            'property_type' => [$need, Rule::enum(PropertyType::class)],
            'title' => [$need, 'string', 'max:160'],
            'city' => [$need, 'string', new KnownCity],
            'neighborhood' => [$need, 'string', new KnownNeighborhood(is_string($city) ? $city : null)],
            'price' => [$need, 'numeric', 'gt:0', 'max:999999999999'],
            'negotiable' => ['sometimes', 'boolean'],
            'area_land' => $spec('area_land', ['numeric', 'gt:0', 'max:99999999']),
            'area_built' => $spec('area_built', ['numeric', 'gt:0', 'max:99999999']),
            'bedrooms' => $spec('bedrooms', ['integer', 'between:0,50']),
            'bathrooms' => $spec('bathrooms', ['integer', 'between:0,50']),
            'halls' => $spec('halls', ['integer', 'between:0,20']),
            'age' => $spec('age', ['integer', 'between:0,100']),
            'floor_level' => $spec('floor_level', [Rule::enum(FloorLevel::class)]),
            'floor_number' => $spec('floor_number', ['integer', 'between:0,200', 'required_if:floor_level,number']),
            'floors' => $spec('floors', ['integer', 'between:1,100']),
            'occupancy' => $spec('occupancy', [Rule::enum(Occupancy::class)]),
            'land_use' => $spec('land_use', [Rule::enum(LandUse::class)]),
            'annual_income' => $spec('annual_income', ['numeric', 'gte:0', 'max:999999999999']),
            'has_deed' => ['sometimes', 'boolean'],
            'bank_accepted' => ['sometimes', 'boolean'],
            'description' => [$need, 'string', 'min:10', 'max:3000'],
            'features' => ['sometimes', 'array', 'max:20'],
            'features.*' => ['string', 'max:60'],
            'lat' => ['nullable', 'numeric', 'between:16,33'],
            'lng' => ['nullable', 'numeric', 'between:34,56'],
            'maps_link' => ['nullable', 'url', 'max:500'],
            'phones' => ['sometimes', 'array', 'max:3'],
            'phones.*' => ['regex:/^\+9665\d{8}$/'],
            'media_ids' => ['sometimes', 'array', 'max:'.Settings::int(Setting::ListingPhotosMax)],
            'media_ids.*' => ['integer', 'distinct'],
            'cover_id' => ['nullable', 'integer'],
            // A broker's REGA advertising licence; an owner publishes from his case (the licence comes with it).
            'licence_number' => $editing ? ['sometimes', 'regex:/^\d{6,12}$/'] : ['nullable', 'regex:/^\d{6,12}$/'],
            'owner_case_id' => $editing ? ['prohibited'] : ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = ['phones.*.regex' => __('validation.phone')];
        foreach (self::SPEC_FIELDS as $field) {
            $messages["{$field}.prohibited"] = __('validation.field_not_for_type');
        }

        return $messages;
    }

    /** @return array<string, mixed> the listing's columns from the form */
    public function columns(): array
    {
        $data = $this->safe()->except(['description', 'media_ids', 'cover_id', 'licence_number', 'owner_case_id']);
        if ($this->has('description')) {
            $data['desc_raw'] = trim((string) $this->input('description'));
        }

        return $data;
    }
}
