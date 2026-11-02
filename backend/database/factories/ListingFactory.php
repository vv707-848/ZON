<?php

namespace Database\Factories;

use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Enums\TextReview;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\Listing;
use Carbon\CarbonImmutable;
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

    /** With its REGA advertising licence, as every listing gets at creation (CreateListing); it runs for a year. */
    public function licensed(): static
    {
        return $this->afterCreating(fn (Listing $listing) => AdLicense::query()->create([
            'listing_id' => $listing->id,
            'customer_id' => $listing->customer_id,
            'licence_number' => '71'.str_pad((string) $listing->id, 8, '0', STR_PAD_LEFT),
            'source' => LicenceSource::Entered,
            'status' => LicenceStatus::Valid,
            'verified_at' => now(),
            'expires_at' => CarbonImmutable::now(AdLicense::TIMEZONE)->addYear()->toDateString(),
        ]));
    }

    public function published(): static
    {
        return $this->licensed()->state(fn () => [
            'status' => ListingStatus::Published,
            'text_review' => TextReview::Approved,
            'desc_clean' => 'شقة واسعة قريبة من الخدمات، مع موقف خاص.',
            'published_at' => now(),
        ]);
    }
}
