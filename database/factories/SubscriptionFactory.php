<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Subscription;
use App\StaticClasses\PlanTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan' => PlanTypes::FINANCIERO,
            'started_at' => now(),
            'ended_at' => now()->addYear(),
            'amount' => 0,
        ];
    }

    public function plan(string $plan): self
    {
        return $this->state(['plan' => $plan]);
    }

    public function expired(): self
    {
        return $this->state([
            'started_at' => now()->subMonths(2),
            'ended_at' => now()->subMonth(),
        ]);
    }
}
