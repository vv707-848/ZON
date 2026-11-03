<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Enums\NafathStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role' => CustomerRole::Seeker,
            'status' => AccountStatus::Active,
            'name' => fake()->name(),
            'username' => fake()->unique()->regexify('[a-z]{5}[0-9]{4}'),
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'password' => 'password',
            'city' => 'الرياض',
            'nafath_status' => NafathStatus::Verified,
            'nafath_verified_at' => now(),
        ];
    }

    public function role(CustomerRole $role): static
    {
        return $this->state(fn () => ['role' => $role] + match ($role) {
            CustomerRole::Broker => ['fal_number' => fake()->numerify('11########'), 'fal_status' => FalStatus::Valid, 'fal_expires_at' => now()->addYear()],
            default => [],
        });
    }

    public function seeker(): static
    {
        return $this->role(CustomerRole::Seeker);
    }

    public function broker(): static
    {
        return $this->role(CustomerRole::Broker);
    }

    public function owner(): static
    {
        return $this->role(CustomerRole::Owner);
    }

    public function developer(): static
    {
        return $this->role(CustomerRole::Developer);
    }

    public function suspended(): static
    {
        return $this->state(['status' => AccountStatus::Suspended]);
    }

    public function pending(): static
    {
        return $this->state(['status' => AccountStatus::Pending]);
    }

    /** A review or test account: it lives in the sandbox (App\Support\Sandbox). */
    public function tester(): static
    {
        return $this->state(['is_tester' => true]);
    }

    public function withoutNafath(): static
    {
        return $this->state(['nafath_status' => NafathStatus::None, 'nafath_verified_at' => null]);
    }
}
