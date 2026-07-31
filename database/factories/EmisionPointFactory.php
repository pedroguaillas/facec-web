<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\EmisionPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmisionPointFactory extends Factory
{
    protected $model = EmisionPoint::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'point' => $this->faker->numberBetween(1, 999),
            'invoice' => $this->faker->numberBetween(1, 100),
            'creditnote' => $this->faker->numberBetween(1, 10),
            'retention' => $this->faker->numberBetween(1, 10),
            'referralguide' => $this->faker->numberBetween(1, 10),
            'settlementonpurchase' => $this->faker->numberBetween(1, 10),
            'recognition' => $this->faker->optional()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function firstPoint(): static
    {
        return $this->state(fn (array $attributes) => [
            'point' => 1,
        ]);
    }
}
