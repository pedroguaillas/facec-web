<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\StaticClasses\PlanTypes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asigna el plan de una empresa como historial: cierra cualquier suscripción
 * vigente (started_at <= hoy <= ended_at) y abre una nueva. Todo plan tiene
 * fecha de fin y monto pagado; GRATUITO sin fecha es un trial de 1 mes y sin
 * monto se guarda en 0.
 */
class SubscriptionService
{
    public function assignPlan(Company $company, string $plan, ?string $endsAt = null, ?float $amount = null): Subscription
    {
        if ($plan !== PlanTypes::GRATUITO && ($endsAt === null || $amount === null)) {
            throw new InvalidArgumentException("El plan {$plan} requiere fecha de fin y monto.");
        }

        $endsAt ??= now()->addMonth()->toDateString();
        $amount ??= 0.0;

        return DB::transaction(function () use ($company, $plan, $endsAt, $amount) {
            $company->subscriptions()
                ->whereDate('started_at', '<=', now())
                ->whereDate('ended_at', '>=', now())
                ->update(['ended_at' => now()]);

            return $company->subscriptions()->create([
                'plan' => $plan,
                'started_at' => now(),
                'ended_at' => $endsAt,
                'amount' => $amount,
            ]);
        });
    }

    public function assignTrial(Company $company): Subscription
    {
        return $this->assignPlan($company, PlanTypes::GRATUITO);
    }
}
