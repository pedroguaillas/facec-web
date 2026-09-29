<?php

namespace Database\Factories\AccountsPayable;

use App\Models\AccountsPayable\ProviderAdvance;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\Treasury\Movement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderAdvance>
 */
class ProviderAdvanceFactory extends Factory
{
    protected $model = ProviderAdvance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'provider_id' => Provider::factory(),
            'movement_id' => Movement::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 500),
            'applied_amount' => 0,
            'date' => now(),
        ];
    }
}
