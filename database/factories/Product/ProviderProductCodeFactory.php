<?php

namespace Database\Factories\Product;

use App\Models\Branch;
use App\Models\Product\Product;
use App\Models\Product\ProviderProductCode;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderProductCode>
 */
class ProviderProductCodeFactory extends Factory
{
    protected $model = ProviderProductCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'provider_id' => Provider::factory(),
            'product_id' => Product::factory(),
            'code' => $this->faker->bothify('??-####'),
        ];
    }
}
