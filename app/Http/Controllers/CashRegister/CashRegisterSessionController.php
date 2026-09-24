<?php

namespace App\Http\Controllers\CashRegister;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\CashRegisterCloseRequest;
use App\Http\Requests\CashRegister\CashRegisterOpenRequest;
use App\Http\Resources\CashRegisterSessionResources;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Services\Treasury\CashRegisterClosingService;
use App\Services\Treasury\CashRegisterOpeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class CashRegisterSessionController extends Controller
{
    public function index(CashRegister $cashRegister): AnonymousResourceCollection
    {
        $sessions = $cashRegister->sessions()
            ->with(['openingDenominations.denomination', 'closingDenominations.denomination'])
            ->latest('opened_at')
            ->paginate(15);

        return CashRegisterSessionResources::collection($sessions);
    }

    public function show(CashRegisterSession $cashRegisterSession): JsonResponse
    {
        $cashRegisterSession->load(['openingDenominations.denomination', 'closingDenominations.denomination']);

        return response()->json(new CashRegisterSessionResources($cashRegisterSession));
    }

    public function open(CashRegisterOpenRequest $request, CashRegister $cashRegister, CashRegisterOpeningService $service): JsonResponse
    {
        try {
            $session = $service->open(
                $cashRegister,
                (float) $request->validated('initial_balance'),
                Auth::user(),
                $request->validated('denominations', []),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(
            new CashRegisterSessionResources($session->load('openingDenominations.denomination')),
            201,
        );
    }

    public function close(CashRegisterCloseRequest $request, CashRegister $cashRegister, CashRegisterClosingService $service): JsonResponse
    {
        try {
            $session = $service->close($cashRegister, Auth::user(), $request->validated('denominations', []));
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(
            new CashRegisterSessionResources($session->load('closingDenominations.denomination')),
        );
    }
}
