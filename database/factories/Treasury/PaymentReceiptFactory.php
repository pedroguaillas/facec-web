<?php

namespace Database\Factories\Treasury;

use App\Models\Branch;
use App\Models\Treasury\PaymentReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentReceipt>
 */
class PaymentReceiptFactory extends Factory
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
            'path' => 'treasury/receipts/'.$this->faker->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'user_id' => User::factory(),
        ];
    }
}
