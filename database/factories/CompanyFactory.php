<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ruc' => $this->faker->numerify('#############'),
            'company' => $this->faker->company(),
            'economic_activity' => $this->faker->sentence(4),
            'accounting' => $this->faker->boolean(),
            'micro_business' => $this->faker->boolean(),
            'retention_agent' => $this->faker->optional()->numberBetween(100, 999),
            'phone' => $this->faker->numerify('09########'),
            'logo_dir' => 'logos/default.png',
            'cert_dir' => 'certs/default.p12',
            'pass_cert' => 'password123',
            'sign_valid_from' => now()->subMonths(6),
            'sign_valid_to' => now()->addYear(),
            'enviroment_type' => 1, // 1: Pruebas, 2: Producción
            'active' => true,
            'active_voucher' => true,
            'decimal' => 2,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'enviroment_type' => 2,
        ]);
    }
}
