<?php

namespace Database\Factories;

use App\Models\Company;
use App\StaticClasses\PlanTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    /**
     * Empresas de prueba arrancan con plan financiero activo por defecto —
     * así el grueso de los tests (cartera, anticipos, pagos) no necesita
     * preocuparse del gating. Los tests que SÍ prueban el gating usan
     * ->withoutSubscription() o ->plan(...) explícitamente.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Company $company) {
            if ($company->subscriptions()->exists()) {
                return;
            }

            $company->subscriptions()->create([
                'plan' => PlanTypes::FINANCIERO,
                'started_at' => now()->subDay(),
                'ended_at' => now()->addYear(),
                'amount' => 0,
            ]);
        });
    }

    public function withoutSubscription(): static
    {
        return $this->afterCreating(function (Company $company) {
            $company->subscriptions()->delete();
        });
    }

    public function plan(string $plan): static
    {
        return $this->afterCreating(function (Company $company) use ($plan) {
            $company->subscriptions()->delete();
            $company->subscriptions()->create([
                'plan' => $plan,
                'started_at' => now()->subDay(),
                'ended_at' => now()->addYear(),
                'amount' => 0,
            ]);
        });
    }

    public function definition(): array
    {
        return [
            'ruc' => $this->faker->numerify('#############'),
            'company' => $this->faker->company(),
            'economic_activity' => $this->faker->sentence(4),
            'accounting' => $this->faker->boolean(),
            'retention_agent' => $this->faker->optional()->numberBetween(100, 999),
            'phone' => $this->faker->numerify('09########'),
            'logo_dir' => 'logos/default.png',
            'cert_dir' => 'certs/default.p12',
            'pass_cert' => 'password123',
            'sign_valid_from' => now()->subMonths(6),
            'sign_valid_to' => now()->addYear(),
            'enviroment_type' => 1, // 1: Pruebas, 2: Producción
            'active' => true,
            'active_voucher' => true,
            'decimal' => 2,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'enviroment_type' => 2,
        ]);
    }
}
