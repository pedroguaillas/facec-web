<?php

namespace App\Exceptions;

use App\StaticClasses\PlanTypes;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La empresa no tiene un plan que habilite la función pedida. No extiende
 * RuntimeException a propósito: los controllers atrapan RuntimeException y la
 * convierten en 422 genérico, y el front necesita distinguir este caso (403 +
 * `error`) para mostrar el aviso de renovar/actualizar plan.
 */
class PlanRequiredException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $requiredPlan = null,
    ) {
        parent::__construct($message);
    }

    /**
     * El plan vigente no incluye cartera (crédito/anticipos/CxC/CxP).
     */
    public static function financiero(): self
    {
        return new self(
            'Esta función requiere el plan financiero. Actualizá tu plan para usarla.',
            'plan_required',
            PlanTypes::FINANCIERO,
        );
    }

    /**
     * No hay ningún plan vigente (venció o nunca se asignó): no puede emitir.
     */
    public static function expired(): self
    {
        return new self(
            'Tu plan está vencido. Renová tu suscripción para seguir emitiendo comprobantes.',
            'subscription_expired',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'succes' => false,
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'required_plan' => $this->requiredPlan,
        ], 403);
    }
}
