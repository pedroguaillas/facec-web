<?php

namespace Database\Factories\Treasury;

use App\Models\Branch;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
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
            'name' => $this->faker->words(2, true),
            'responsible_id' => User::factory(),
            'type' => CashRegisterTypes::OTHER,
            'status' => CashRegisterStatuses::CLOSED,
        ];
    }

    /** Caja con apertura y cierre (arqueo) por sesión. */
    public function withSessions(): self
    {
        return $this->state(fn () => ['uses_sessions' => true]);
    }

    public function general(): self
    {
        return $this->state(fn () => [
            'type' => CashRegisterTypes::GENERAL,
            'status' => CashRegisterStatuses::OPEN,
        ]);
    }
}
