<?php

namespace App\Http\Controllers\CashRegister;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\CashRegisterStoreRequest;
use App\Http\Requests\CashRegister\CashRegisterUpdateRequest;
use App\Http\Resources\CashRegisterResources;
use App\Models\Treasury\CashRegister;
use App\Services\Treasury\CashRegisterOpeningService;
use App\StaticClasses\CashRegisterStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CashRegisterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginate = (int) $request->input('paginate', 15);

        $cashRegisters = CashRegister::with(['activeSession', 'responsible'])
            ->latest()
            ->paginate($paginate)
            ->withQueryString();

        return CashRegisterResources::collection($cashRegisters);
    }

    /**
     * Sin apertura/cierre (`uses_sessions` false, el default) la caja se abre
     * sola con `initial_balance`, lista para cobrar.
     */
    public function store(CashRegisterStoreRequest $request, CashRegisterOpeningService $openingService): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        try {
            $cashRegister = DB::transaction(function () use ($request, $branch, $openingService) {
                $cashRegister = CashRegister::create([
                    ...$request->safe()->except('initial_balance'),
                    'responsible_id' => $request->validated('responsible_id', Auth::id()),
                    'uses_sessions' => $request->boolean('uses_sessions'),
                    'branch_id' => $branch->id,
                    'status' => CashRegisterStatuses::CLOSED,
                ]);

                if (! $cashRegister->uses_sessions) {
                    $openingService->open($cashRegister, (float) $request->validated('initial_balance', 0), Auth::user());
                }

                return $cashRegister;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json($cashRegister->fresh()->load('activeSession'), 201);
    }

    public function edit(CashRegister $cashRegister): JsonResponse
    {
        return response()->json($cashRegister->load('activeSession'));
    }

    /**
     * Al desactivar apertura/cierre, si la caja quedó cerrada se reabre en 0
     * (su último cierre ya devolvió el saldo a la caja general).
     */
    public function update(CashRegisterUpdateRequest $request, CashRegister $cashRegister, CashRegisterOpeningService $openingService): JsonResponse
    {
        DB::transaction(function () use ($request, $cashRegister, $openingService) {
            $cashRegister->update($request->validated());

            if (! $cashRegister->uses_sessions && ! $cashRegister->activeSession()->exists()) {
                $openingService->open($cashRegister, 0, Auth::user());
            }
        });

        return response()->json($cashRegister->load('activeSession'));
    }
}
