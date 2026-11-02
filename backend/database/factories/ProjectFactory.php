<?php

namespace Database\Factories;

use App\Enums\Delivery;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'developer_id' => Customer::factory()->developer(),
            'status' => ListingStatus::Draft,
            'title' => 'مشروع النرجس السكني',
            'city' => 'الرياض',
            'neighborhood' => 'النرجس',
            'property_type' => PropertyType::Villa,
            'delivery' => Delivery::Offplan,
            'total_units' => 40,
            'desc_raw' => 'فلل حديثة بتشطيب فاخر قريبة من طريق الملك سلمان.',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Published,
            'text_review' => TextReview::Approved,
            'desc_clean' => 'فلل حديثة بتشطيب فاخر، قريبة من طريق الملك سلمان.',
            'published_at' => now(),
        ]);
    }
}
