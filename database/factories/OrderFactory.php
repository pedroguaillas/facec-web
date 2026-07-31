<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order\Order;
use App\StaticClasses\VoucherStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'date' => now(),
            'description' => null,
            'sub_total' => $this->faker->numerify,
            'serie' => '001-001-'.$this->faker->randomElement([1, 999]),
            'customer_id' => Customer::factory(),
            'doc_realeted' => null,
            'expiration_days' => 0,
            'no_iva' => 0,
            'base0' => 0,
            'base12' => 0,
            'ice' => 0,
            'iva' => 0,
            'discount' => 0,
            'total' => 0,
            'voucher_type' => 1,
            'paid' => 0,
            'state' => VoucherStates::SAVED,
            'autorized' => null,
            'authorization' => null,
            'iva_retention' => 0,
            'rent_retention' => 0,
            'xml' => null,
            'extra_detail' => null,
            'send_mail' => 0,
            'guia' => null,
            'serie_retencion' => null,
            'date_retention' => null,
            'authorization_retention' => null,
            'pay_method' => 1,
            'date_order' => null,
            'serie_order' => null,
            'reason' => null,
            'base5' => 0,
            'base8' => 0,
            'base15' => 0,
            'iva5' => 0,
            'iva8' => 0,
            'iva15' => 0,
            'lot_id' => null,
        ];
    }
}
