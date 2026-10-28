<?php

namespace Database\Factories\Product;

use App\Models\Product\Product;
use App\Models\Product\ProductDetail;
use App\Models\Product\ProductTemplateField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductDetail>
 */
class ProductDetailFactory extends Factory
{
    protected $model = ProductDetail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_template_field_id' => ProductTemplateField::factory(),
            'value' => fake()->bothify('??####'),
        ];
    }
}
