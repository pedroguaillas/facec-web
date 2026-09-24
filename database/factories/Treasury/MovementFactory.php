<?php

namespace Database\Factories\Treasury;

use App\Models\Branch;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movement>
 */
class MovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'date' => now()->format('Y-m-d'),
            'type' => MovementTypes::EXPENSE,
            'transaction_type' => MovementTransactionTypes::OTHER,
            'amount' => $this->faker->randomFloat(2, 1, 100),
            'status' => MovementStatuses::PENDING,
            'payment_method' => PaymentMethods::CASH,
            'description' => $this->faker->sentence,
            'user_id' => User::factory(),
        ];
    }
}
