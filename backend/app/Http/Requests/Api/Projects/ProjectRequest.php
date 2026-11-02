<?php

namespace App\Http\Requests\Api\Projects;

use App\Enums\Delivery;
use App\Enums\LandUse;
use App\Enums\PropertyType;
use App\Http\Requests\Api\ApiRequest;
use App\Models\Project;
use App\Rules\KnownCity;
use App\Rules\KnownNeighborhood;
use App\Support\Phone;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Closure;
use Illuminate\Validation\Rule;

/** A developer's project (project_form.dart): the project itself; its unit models come with UnitRequest. */
class ProjectRequest extends ApiRequest
{
    protected array $numeric = ['total_units'];

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('phones'))) {
            $this->merge(['phones' => array_map(fn ($p) => is_string($p) ? (Phone::normalize($p) ?? $p) : $p, $this->input('phones'))]);
        }
        parent::prepareForValidation();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $editing = $this->record() instanceof Project;
        $need = $editing ? 'sometimes' : 'required';
        $city = $this->input('city', $editing ? $this->record()->city : null);

        return [
            'title' => [$need, 'string', 'max:160'],
            'city' => [$need, 'string', new KnownCity],
            'neighborhood' => [$need, 'string', new KnownNeighborhood(is_string($city) ? $city : null)],
            'property_type' => [$need, Rule::enum(PropertyType::class), function (string $attribute, mixed $value, Closure $fail) {
                if (! (PropertyType::tryFrom((string) $value)?->forProjects() ?? true)) {
                    $fail(__('validation.in', ['attribute' => __('validation.attributes.property_type')]));
                }
            }],
            'delivery' => [$need, Rule::enum(Delivery::class)],
            'land_use' => ['nullable', Rule::enum(LandUse::class)],
            'total_units' => [$need, 'integer', 'between:1,5000'],
            'description' => [$need, 'string', 'min:10', 'max:4000'],
            'lat' => ['nullable', 'numeric', 'between:16,33'],
            'lng' => ['nullable', 'numeric', 'between:34,56'],
            'maps_link' => ['nullable', 'url', 'max:500'],
            'phones' => ['sometimes', 'array', 'max:3'],
            'phones.*' => ['regex:/^\+9665\d{8}$/'],
            'media_ids' => ['sometimes', 'array', 'max:'.Settings::int(Setting::ListingPhotosMax)],
            'media_ids.*' => ['integer', 'distinct'],
            'cover_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->except(['description', 'media_ids', 'cover_id']);
        if ($this->has('description')) {
            $data['desc_raw'] = trim((string) $this->input('description'));
        }

        return $data;
    }
}
