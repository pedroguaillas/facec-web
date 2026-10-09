<?php

namespace App\Http\Middleware;

use App\Exceptions\PlanRequiredException;
use App\Services\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea la emisión de comprobantes (crear y procesar ante el SRI) cuando la
 * empresa no tiene plan vigente. Las lecturas, PDFs y XML siguen disponibles.
 */
class EnsureActiveSubscription
{
    public function __construct(private SubscriptionGate $gate) {}

    /**
     * @throws PlanRequiredException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        if ($company) {
            $this->gate->ensureActiveSubscription($company);
        }

        return $next($request);
    }
}
