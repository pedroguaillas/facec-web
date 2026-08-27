<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => $this->faker->unique()->bothify('PROD-####'),
            'type_product' => 1,
            'name' => $this->faker->words(3, true),
            'price1' => $this->faker->randomFloat(2, 1, 100),
            'iva' => 2,
            'stock' => $this->faker->numberBetween(0, 100),
        ];
    }
}
