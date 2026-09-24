<?php

namespace Database\Factories\AccountsReceivable;

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order\Order;
use App\StaticClasses\AccountsReceivableMovementTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountReceivableMovement>
 */
class AccountReceivableMovementFactory extends Factory
{
    protected $model = AccountReceivableMovement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'order_id' => Order::factory(),
            'type' => AccountsReceivableMovementTypes::FACTURA,
            'debe' => $this->faker->randomFloat(2, 10, 500),
            'haber' => 0,
            'previous_balance' => 0,
            'new_balance' => 0,
            'description' => $this->faker->sentence(),
            'date' => now(),
        ];
    }
}
