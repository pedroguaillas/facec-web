<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\Treasury\CashRegister;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\PlanTypes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Asigna el plan de una empresa como historial: cierra cualquier suscripción
 * vigente (started_at <= hoy <= ended_at) y abre una nueva. Todo plan tiene
 * fecha de fin y monto pagado; GRATUITO sin fecha es un trial de 1 mes y sin
 * monto se guarda en 0.
 */
class SubscriptionService
{
    /** Días de la prueba del plan Financiero. */
    const FINANCIERO_TRIAL_DAYS = 30;

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

    /**
     * La prueba se ofrece una sola vez y solo sobre un FACTURADOR vigente:
     * sin plan vigente no hay "plan anterior" al que volver.
     */
    public function financieroTrialAvailable(Company $company): bool
    {
        return $company->currentSubscription()?->plan === PlanTypes::FACTURADOR
            && ! $company->subscriptions()->where('is_trial', true)->where('plan', PlanTypes::FINANCIERO)->exists();
    }

    /**
     * Abre FINANCIERO por 30 días sin cerrar el FACTURADOR vigente: como el
     * plan vigente es el de started_at más reciente, al vencer la prueba la
     * empresa vuelve sola al FACTURADOR (si su ended_at sigue en el futuro).
     *
     * @throws RuntimeException
     */
    public function startFinancieroTrial(Company $company): Subscription
    {
        return DB::transaction(function () use ($company) {
            // Serializa dos clics simultáneos: el segundo ve la prueba ya creada.
            Company::whereKey($company->id)->lockForUpdate()->first();

            $current = $company->currentSubscription();

            if ($current !== null && in_array($current->plan, PlanTypes::FINANCIERO_LEVEL, true)) {
                throw new RuntimeException('Tu plan ya incluye las funciones del plan Financiero.');
            }

            if ($company->subscriptions()->where('is_trial', true)->where('plan', PlanTypes::FINANCIERO)->exists()) {
                throw new RuntimeException('Tu empresa ya usó la prueba del plan Financiero.');
            }

            if ($current === null) {
                throw new RuntimeException('Necesitas un plan vigente para activar la prueba del plan Financiero.');
            }

            return $company->subscriptions()->create([
                'plan' => PlanTypes::FINANCIERO,
                'started_at' => now(),
                'ended_at' => now()->addDays(self::FINANCIERO_TRIAL_DAYS),
                'amount' => 0,
                'is_trial' => true,
            ]);
        });
    }

    /**
     * Plan vigente tal como lo ve el front (mismas claves en login y en
     * /subscription/current).
     *
     * @return array{plan: ?string, plan_ends_at: ?string, financiero: bool, financiero_trial_available: bool}
     */
    public function planSummary(Company $company): array
    {
        $subscription = $company->currentSubscription();

        return [
            'plan' => $subscription?->plan,
            'plan_ends_at' => $subscription?->ended_at?->toDateString(),
            'financiero' => $subscription !== null && in_array($subscription->plan, PlanTypes::FINANCIERO_LEVEL, true),
            'financiero_trial_available' => $this->financieroTrialAvailable($company),
        ];
    }

    /**
     * Con plan financiero, falta configurar tesorería mientras la sucursal no
     * tenga caja GENERAL (los bancos son opcionales).
     */
    public function needsFinanceSetup(Company $company, bool $financiero): bool
    {
        return $financiero
            && ! CashRegister::where('type', CashRegisterTypes::GENERAL)->exists();
    }
}
