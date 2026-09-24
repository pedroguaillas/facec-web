<?php

namespace App\Http\Controllers\AccountsPayable;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\ShopPaymentRequest;
use App\Http\Resources\AccountPayableMovementResource;
use App\Models\Shop\Shop;
use App\Services\AccountsPayable\AccountsPayablePaymentService;
use Illuminate\Http\JsonResponse;

class AccountPayablePaymentController extends Controller
{
    public function store(ShopPaymentRequest $request, Shop $shop, AccountsPayablePaymentService $service): JsonResponse
    {
        try {
            $movement = $service->pay(
                $shop,
                (float) $request->validated('amount'),
                $request->validated('source_type'),
                (int) $request->validated('source_id'),
                $request->validated('date'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new AccountPayableMovementResource($movement), 201);
    }
}
