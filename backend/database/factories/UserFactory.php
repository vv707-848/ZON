<?php

namespace Database\Factories;

use App\Enums\StaffType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => StaffType::Admin,
            'is_active' => true,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(['type' => StaffType::Superadmin]);
    }
}
