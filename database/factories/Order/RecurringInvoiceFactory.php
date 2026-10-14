<?php

namespace Database\Factories\Order;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\RecurringInvoice;
use App\Models\User;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\RecurringFrequencies;
use App\StaticClasses\RecurringInvoiceStatuses;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
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
            'customer_id' => Customer::factory(),
            'emision_point_id' => EmisionPoint::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'frequency' => RecurringFrequencies::MONTHLY,
            'start_at' => now()->startOfMinute(),
            'next_run_at' => now()->startOfMinute(),
            'status' => RecurringInvoiceStatuses::ACTIVE,
            'auto_send' => false,
            'payment_type' => PaymentTypes::CREDITO,
            'due_days' => 15,
            'pay_method' => 20,
        ];
    }
}
