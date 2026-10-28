<?php

namespace App\Http\Controllers\AccountsReceivable;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountsReceivable\AdvanceRequest;
use App\Http\Requests\AccountsReceivable\OpeningBalanceRequest;
use App\Http\Resources\AccountReceivableMovementResource;
use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\AccountsReceivable\CustomerAdvance;
use App\Models\Customer;
use App\Services\AccountsReceivable\AccountsReceivableAdvanceService;
use App\Services\AccountsReceivable\AccountsReceivableLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountReceivableController extends Controller
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        $movements = AccountReceivableMovement::where('customer_id', $customer->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return AccountReceivableMovementResource::collection($movements);
    }

    public function openingBalance(OpeningBalanceRequest $request, Customer $customer, AccountsReceivableLedgerService $service): JsonResponse
    {
        try {
            $movement = $service->registerOpeningBalance(
                $customer,
                (float) $request->validated('amount'),
                $request->validated('date'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new AccountReceivableMovementResource($movement), 201);
    }

    public function storeAdvance(AdvanceRequest $request, Customer $customer, AccountsReceivableAdvanceService $service): JsonResponse
    {
        try {
            $advance = $service->register(
                $customer,
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

    public function advances(Customer $customer): JsonResponse
    {
        $advances = CustomerAdvance::where('customer_id', $customer->id)
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
