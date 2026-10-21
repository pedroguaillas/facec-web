<?php

namespace Database\Factories\Product;

use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPresentation>
 */
class ProductPresentationFactory extends Factory
{
    protected $model = ProductPresentation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Caja x'.$this->faker->randomElement([6, 12, 24]),
            'conversion_factor' => $this->faker->randomElement([6, 12, 24]),
            'price' => null,
            'code' => null,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
