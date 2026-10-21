<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\WarehouseStoreRequest;
use App\Models\Product\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class WarehouseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Warehouse::orderBy('created_at')->get());
    }

    public function store(WarehouseStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $warehouse = Warehouse::create([
            ...$request->validated(),
            'branch_id' => $branch->id,
        ]);

        return response()->json($warehouse, 201);
    }

    public function update(WarehouseStoreRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse->update($request->validated());

        return response()->json($warehouse);
    }

    /**
     * Una bodega con movimientos es parte del kardex y no se puede borrar.
     */
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        if ($warehouse->inventories()->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'La bodega tiene movimientos de inventario y no se puede eliminar.',
            ], 422);
        }

        $warehouse->delete();

        return response()->json(['succes' => true, 'message' => 'Bodega eliminada con éxito.']);
    }
}
