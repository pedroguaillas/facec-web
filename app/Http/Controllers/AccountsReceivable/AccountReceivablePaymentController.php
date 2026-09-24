<?php

namespace App\Http\Controllers\AccountsReceivable;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderPaymentRequest;
use App\Http\Resources\AccountReceivableMovementResource;
use App\Models\Order\Order;
use App\Services\AccountsReceivable\AccountsReceivablePaymentService;
use Illuminate\Http\JsonResponse;

class AccountReceivablePaymentController extends Controller
{
    public function store(OrderPaymentRequest $request, Order $order, AccountsReceivablePaymentService $service): JsonResponse
    {
        try {
            $movement = $service->pay(
                $order,
                (float) $request->validated('amount'),
                $request->validated('source_type'),
                (int) $request->validated('source_id'),
                $request->validated('date'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new AccountReceivableMovementResource($movement), 201);
    }
}
