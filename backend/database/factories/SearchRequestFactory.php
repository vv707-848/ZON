<?php

namespace Database\Factories;

use App\Enums\Purpose;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\SearchRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SearchRequest> */
class SearchRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->seeker(),
            'status' => RequestStatus::Published,
            'purpose' => Purpose::Sale,
            'city' => 'الرياض',
            'neighborhoods' => ['النرجس', 'الملقا'],
            'property_types' => ['villa'],
            'budget_min' => 1500000,
            'budget_max' => 2200000,
            'rooms_min' => 4,
            'description' => 'فيلا بمجلس مستقل وحوش.',
            'urgent' => false,
        ];
    }

    public function urgent(): static
    {
        return $this->state(['urgent' => true]);
    }
}
