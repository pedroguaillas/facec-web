<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $identification = $this->faker->randomElement([
            $this->faker->numerify('##########'),    // 10 dígitos
            $this->faker->numerify('#############'),  // 13 dígitos
        ]);

        return [
            'branch_id' => Branch::factory(),
            'state' => $this->faker->randomElement([0, 1]),
            'type_identification' => strlen($identification) === 10 ? 'cedula' : 'ruc',
            'identication' => $identification,
            'name' => $this->faker->name,
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->email,
            'accounting' => $this->faker->randomElement([true, false]),
            'discount' => 0,
        ];
    }
}
