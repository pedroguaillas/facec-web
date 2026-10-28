<?php

namespace App\Http\Controllers\AccountsPayable;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsPayable\AdvanceRequest;
use App\Http\Requests\AccountsPayable\OpeningBalanceRequest;
use App\Http\Resources\AccountPayableMovementResource;
use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\AccountsPayable\ProviderAdvance;
use App\Models\Provider;
use App\Services\AccountsPayable\AccountsPayableAdvanceService;
use App\Services\AccountsPayable\AccountsPayableLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountPayableController extends Controller
{
    public function index(Provider $provider): AnonymousResourceCollection
    {
        $movements = AccountPayableMovement::where('provider_id', $provider->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return AccountPayableMovementResource::collection($movements);
    }

    public function openingBalance(OpeningBalanceRequest $request, Provider $provider, AccountsPayableLedgerService $service): JsonResponse
    {
        try {
            $movement = $service->registerOpeningBalance(
                $provider,
                (float) $request->validated('amount'),
                $request->validated('date'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new AccountPayableMovementResource($movement), 201);
    }

    public function storeAdvance(AdvanceRequest $request, Provider $provider, AccountsPayableAdvanceService $service): JsonResponse
    {
        try {
            $advance = $service->register(
                $provider,
                (float) $request->validated('amount'),
                $request->validated('source_type'),
                (int) $request->validated('source_id'),
                $request->validated('date'),
                $request->safe()->only(['receipt_id', 'receipt_number']),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $advance->id,
            'amount' => $advance->amount,
            'applied_amount' => $advance->applied_amount,
            'remaining' => $advance->amount - $advance->applied_amount,
            'date' => $advance->date->format('Y-m-d'),
        ], 201);
    }

    public function advances(Provider $provider): JsonResponse
    {
        $advances = ProviderAdvance::where('provider_id', $provider->id)
            ->whereColumn('applied_amount', '<', 'amount')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'total_remaining' => $advances->sum(fn ($advance) => $advance->amount - $advance->applied_amount),
            'advances' => $advances->map(fn ($advance) => [
                'id' => $advance->id,
                'amount' => $advance->amount,
                'applied_amount' => $advance->applied_amount,
                'remaining' => $advance->amount - $advance->applied_amount,
                'date' => $advance->date->format('Y-m-d'),
            ]),
        ]);
    }
}
