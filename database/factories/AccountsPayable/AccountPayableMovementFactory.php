<?php

namespace Database\Factories\AccountsPayable;

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\StaticClasses\AccountsPayableMovementTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountPayableMovement>
 */
class AccountPayableMovementFactory extends Factory
{
    protected $model = AccountPayableMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'provider_id' => Provider::factory(),
            'shop_id' => Shop::factory(),
            'type' => AccountsPayableMovementTypes::FACTURA,
            'debe' => 0,
            'haber' => $this->faker->randomFloat(2, 10, 500),
            'previous_balance' => 0,
            'new_balance' => 0,
            'description' => $this->faker->sentence(),
            'date' => now(),
        ];
    }
}
