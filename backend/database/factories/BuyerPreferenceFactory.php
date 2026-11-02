<?php

namespace Database\Factories;

use App\Enums\Purpose;
use App\Models\BuyerPreference;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BuyerPreference> */
class BuyerPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->seeker(),
            'purpose' => Purpose::Sale,
            'city' => 'الرياض',
            'neighborhoods' => ['النرجس'],
            'property_types' => ['apartment'],
            'budget_max' => 900000,
            'notes' => 'قريب من مدرسة',
            'is_active' => true,
        ];
    }
}
