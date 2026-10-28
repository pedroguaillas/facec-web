<?php

namespace Database\Factories\Product;

use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductTemplateField>
 */
class ProductTemplateFieldFactory extends Factory
{
    protected $model = ProductTemplateField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->word();

        return [
            'product_template_id' => ProductTemplate::factory(),
            'key' => $label,
            'label' => ucfirst($label),
            'type' => ProductTemplateField::TYPE_TEXT,
            'level' => ProductTemplateField::LEVEL_MODEL,
            'required' => false,
            'unique' => false,
            'in_sri' => false,
            'sort' => 0,
            'active' => true,
        ];
    }

    /** Campo único de cada unidad física (chasis, motor). */
    public function uniqueUnit(): static
    {
        return $this->state(fn () => [
            'level' => ProductTemplateField::LEVEL_UNIT,
            'required' => true,
            'unique' => true,
        ]);
    }
}
