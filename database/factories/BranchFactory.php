<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'store' => $this->faker->unique()->numberBetween(1, 999),
            'address' => $this->faker->address(),
            'name' => $this->faker->optional()->company(),
            'type' => $this->faker->randomElement(['matriz', 'sucursal']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
