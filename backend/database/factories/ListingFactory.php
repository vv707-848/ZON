<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Enums\TextReview;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->broker(),
            'status' => ListingStatus::Draft,
            'purpose' => Purpose::Sale,
            'property_type' => PropertyType::Apartment,
            'title' => 'شقة للبيع في النرجس',
            'city' => 'الرياض',
            'neighborhood' => 'النرجس',
            'price' => 880000,
            'area_built' => 150,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'halls' => 1,
            'age' => 4,
            'has_deed' => true,
            'bank_accepted' => true,
            'desc_raw' => 'شقة واسعة قريبة من الخدمات مع موقف خاص.',
            'last_activity_at' => now(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ListingStatus::Published,
            'text_review' => TextReview::Approved,
            'desc_clean' => 'شقة واسعة قريبة من الخدمات، مع موقف خاص.',
            'published_at' => now(),
        ]);
    }
}
