<?php

namespace Database\Factories\AccountsReceivable;

use App\Models\AccountsReceivable\CustomerAdvance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Treasury\Movement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAdvance>
 */
class CustomerAdvanceFactory extends Factory
{
    protected $model = CustomerAdvance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'movement_id' => Movement::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 500),
            'applied_amount' => 0,
            'date' => now(),
        ];
    }
}
