<?php

namespace App\Services;

use App\Exceptions\PlanRequiredException;
use App\Models\Company;
use App\StaticClasses\PlanTypes;

/**
 * Punto único de validación de acceso por plan.
 *
 * - ensureActiveSubscription: cualquier plan vigente. Lo usa el middleware
 *   `subscription.active` en las rutas de emisión (crear/procesar comprobantes).
 * - ensureFinancieroAccess: plan con cartera. Se llama solo desde lo que crea
 *   deuda nueva (venta/compra a crédito o anticipo, anticipos, saldo inicial).
 *   Abonos sobre cartera existente y lecturas siguen abiertos aunque el plan
 *   haya bajado o vencido, para que la empresa pueda cobrar/pagar lo pendiente.
 */
class SubscriptionGate
{
    /**
     * @throws PlanRequiredException
     */
    public function ensureActiveSubscription(Company $company): void
    {
        if (! $company->currentSubscription()) {
            throw PlanRequiredException::expired();
        }
    }

    /**
     * Plan vigente con cartera/tesorería (GRATUITO o FINANCIERO). Con
     * FACTURADOR la venta/compra al contado no pide caja/banco ni genera
     * movimiento de tesorería.
     */
    public function hasFinancieroAccess(?Company $company): bool
    {
        $current = $company?->currentSubscription();

        return $current !== null && in_array($current->plan, PlanTypes::FINANCIERO_LEVEL, true);
    }

    /**
     * @throws PlanRequiredException
     */
    public function ensureFinancieroAccess(Company $company): void
    {
        $current = $company->currentSubscription();

        if (! $current) {
            throw PlanRequiredException::expired();
        }

        if (! in_array($current->plan, PlanTypes::FINANCIERO_LEVEL, true)) {
            throw PlanRequiredException::financiero();
        }
    }
}
