<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los endpoints de inventario (bodegas, kardex, ajustes, reportes) solo
 * aplican a empresas con `inventory` activo.
 */
class EnsureInventoryEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->company?->inventory) {
            return response()->json([
                'succes' => false,
                'message' => 'El inventario no está activo para esta empresa.',
            ], 422);
        }

        return $next($request);
    }
}
