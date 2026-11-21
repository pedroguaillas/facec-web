<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderRetentionImportRequest;
use App\Http\Requests\Order\OrderRetentionStoreRequest;
use App\Models\Order\Order;
use App\Services\Order\OrderRetentionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Retención recibida del cliente sobre una factura de venta (ver docs/ventas.md §3.3).
 */
class OrderRetentionController extends Controller
{
    public function store(OrderRetentionStoreRequest $request, Order $order, OrderRetentionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->register($order, $request->validated()), 201);
    }

    public function import(OrderRetentionImportRequest $request, Order $order, OrderRetentionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->import($order, (string) $request->file('xml')->get()), 201);
    }

    public function destroy(Order $order, OrderRetentionService $service): JsonResponse
    {
        return $this->respond(fn () => $service->remove($order));
    }

    /**
     * @param  callable(): Order  $action
     */
    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            $order = $action();
        } catch (RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'retention' => $order->retentionSummary()], $status);
    }
}
