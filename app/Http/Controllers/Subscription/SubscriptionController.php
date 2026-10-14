<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    /**
     * Plan vigente para refrescar la sesión del front sin volver a iniciar
     * sesión. `needs_finance_setup`: con financiero, aún no hay caja GENERAL.
     */
    public function current(): JsonResponse
    {
        $company = Auth::user()->company;
        $summary = $this->subscriptionService->planSummary($company);

        return response()->json([
            ...$summary,
            'needs_finance_setup' => $this->subscriptionService->needsFinanceSetup($company, $summary['financiero']),
        ]);
    }

    /**
     * Prueba de 30 días del plan Financiero, una sola vez por empresa.
     */
    public function startFinancieroTrial(): JsonResponse
    {
        $company = Auth::user()->company;

        try {
            $trial = $this->subscriptionService->startFinancieroTrial($company);
        } catch (RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        $permissions = $this->subscriptionService->planSummary($company);

        return response()->json([
            'succes' => true,
            'permissions' => $permissions,
            'trial_ends_at' => $trial->ended_at->toDateString(),
            'needs_finance_setup' => $this->subscriptionService->needsFinanceSetup($company, $permissions['financiero']),
        ], 201);
    }
}
