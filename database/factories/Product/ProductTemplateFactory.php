<?php

namespace Database\Factories\Product;

use App\Models\Branch;
use App\Models\Product\ProductTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductTemplate>
 */
class ProductTemplateFactory extends Factory
{
    protected $model = ProductTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->word(),
            'code_prefix' => null,
            'code_field_id' => null,
            'active' => true,
        ];
    }
}
