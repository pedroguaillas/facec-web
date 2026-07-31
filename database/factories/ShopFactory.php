<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\StaticClasses\VoucherStates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

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
            'sub_total' => 0,
            'serie' => $this->faker->numberBetween([1, 999]),
            'provider_id' => Provider::factory(),
            'doc_realeted' => null,
            'expiration_days' => 0,
            'no_iva' => 0,
            'base0' => 0,
            'base12' => 0,
            'iva' => 0,
            'discount' => 0,
            'ice' => 0,
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
            'send_mail_set_purchase' => 0,
            'serie_retencion' => null,
            'date_retention' => null,
            'send_mail_retention' => 0,
            'state_retencion' => null,
            'autorized_retention' => null,
            'authorization_retention' => null,
            'xml_retention' => null,
            'extra_detail_retention' => null,
            'base5' => 0,
            'base15' => 0,
            'iva5' => 0,
            'iva15' => 0,
        ];
    }
}
