<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustmentStoreRequest;
use App\Http\Requests\Inventory\OpeningBalanceStoreRequest;
use App\Http\Requests\Inventory\TransferStoreRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Inventory;
use App\Models\Product\Warehouse;
use App\Services\Product\InventoryOperationService;
use App\Services\Product\WarehouseResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class InventoryOperationController extends Controller
{
    public function __construct(private InventoryOperationService $operations) {}

    public function openingBalance(OpeningBalanceStoreRequest $request): JsonResponse
    {
        $movements = $this->operations->openingBalance(
            $this->warehouse($request->validated('warehouse_id')),
            $request->validated('date'),
            $request->validated('items'),
            $request->validated('note'),
        );

        return $this->created($movements, 'Inventario inicial registrado con éxito.');
    }

    public function adjustment(AdjustmentStoreRequest $request): JsonResponse
    {
        $movements = $this->operations->adjustment(
            $this->warehouse($request->validated('warehouse_id')),
            $request->validated('direction'),
            $request->validated('date'),
            $request->validated('note'),
            $request->validated('items'),
        );

        return $this->created($movements, 'Ajuste registrado con éxito.');
    }

    public function transfer(TransferStoreRequest $request): JsonResponse
    {
        $movements = $this->operations->transfer(
            Warehouse::findOrFail($request->validated('from_warehouse_id')),
            Warehouse::findOrFail($request->validated('to_warehouse_id')),
            $request->validated('date'),
            $request->validated('items'),
            $request->validated('note'),
        );

        return $this->created($movements, 'Transferencia registrada con éxito.');
    }

    /**
     * Sin `warehouse_id` va a la bodega por defecto de la sucursal.
     */
    private function warehouse(?int $warehouseId): Warehouse
    {
        if ($warehouseId) {
            return Warehouse::findOrFail($warehouseId);
        }

        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        return app(WarehouseResolver::class)->forBranch($branch);
    }

    /**
     * @param  Collection<int, Inventory>  $movements
     */
    private function created(Collection $movements, string $message): JsonResponse
    {
        return response()->json([
            'succes' => true,
            'message' => $message,
            'document_uuid' => $movements->first()?->document_uuid,
            'movements' => InventoryMovementResource::collection($movements->values()),
        ], 201);
    }
}
