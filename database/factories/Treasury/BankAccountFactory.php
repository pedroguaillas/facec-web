<?php

namespace Database\Factories\Treasury;

use App\Models\Branch;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\StaticClasses\BankAccountTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
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
            'bank_id' => Bank::factory(),
            'account_number' => $this->faker->numerify('##########'),
            'type' => $this->faker->randomElement(BankAccountTypes::ALL),
            'balance' => 0,
        ];
    }
}
