<?php

namespace Database\Factories\Treasury;

use App\Models\Branch;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\User;
use App\StaticClasses\CashRegisterStatuses;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegisterSession>
 */
class CashRegisterSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $initialBalance = 0;

        return [
            'branch_id' => Branch::factory(),
            'cash_register_id' => CashRegister::factory(),
            'opened_by' => User::factory(),
            'closed_by' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'initial_balance' => $initialBalance,
            'current_balance' => $initialBalance,
            'final_balance' => null,
            'status' => CashRegisterStatuses::OPEN,
        ];
    }
}
