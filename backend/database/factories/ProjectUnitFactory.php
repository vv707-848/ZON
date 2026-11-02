<?php

namespace Database\Factories;

use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectUnit> */
class ProjectUnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'model_name' => fake()->unique()->bothify('?#'),
            'property_type' => PropertyType::Villa,
            'area_land' => 300,
            'area_built' => 380,
            'bedrooms' => 5,
            'bathrooms' => 4,
            'halls' => 2,
            'price' => 1950000,
            'available_count' => 6,
            'desc_raw' => 'نموذج بخمس غرف ومجلس مستقل.',
            'desc_clean' => 'نموذج بخمس غرف ومجلس مستقل.',
            'text_review' => TextReview::Approved,
        ];
    }
}
