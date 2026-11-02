<?php

namespace App\Http\Requests\Api\Projects;

use App\Enums\LandUse;
use App\Enums\PropertyType;
use App\Http\Requests\Api\ApiRequest;
use App\Models\ProjectUnit;
use Closure;
use Illuminate\Validation\Rule;

/** One unit model of a project («C1»): one fixed price, how many are left, its own description. */
class UnitRequest extends ApiRequest
{
    protected array $numeric = ['price', 'area', 'area_land', 'area_built', 'bedrooms', 'bathrooms', 'halls', 'available_count'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unit = $this->route('unit');
        $editing = $unit instanceof ProjectUnit;
        $need = $editing ? 'sometimes' : 'required';
        $project = $this->route('project');

        return [
            'model_name' => [$need, 'string', 'max:40', Rule::unique('project_units')->where('project_id', is_object($project) ? $project->id : $project)->ignore($editing ? $unit->id : null)],
            'property_type' => [$need, Rule::enum(PropertyType::class), function (string $attribute, mixed $value, Closure $fail) {
                if (! (PropertyType::tryFrom((string) $value)?->forUnits() ?? true)) {
                    $fail(__('validation.in', ['attribute' => __('validation.attributes.property_type')]));
                }
            }],
            'area' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'area_land' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'area_built' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'bedrooms' => ['nullable', 'integer', 'between:0,50'],
            'bathrooms' => ['nullable', 'integer', 'between:0,50'],
            'halls' => ['nullable', 'integer', 'between:0,20'],
            'land_use' => ['nullable', Rule::enum(LandUse::class)],
            'price' => [$need, 'numeric', 'gt:0', 'max:999999999999'],
            'available_count' => [$need, 'integer', 'between:0,5000'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'description' => [$need, 'string', 'min:10', 'max:2000'],
            'media_ids' => ['sometimes', 'array', 'max:12'],
            'media_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        $data = $this->safe()->except(['description', 'media_ids']);
        if ($this->has('description')) {
            $data['desc_raw'] = trim((string) $this->input('description'));
        }

        return $data;
    }
}
