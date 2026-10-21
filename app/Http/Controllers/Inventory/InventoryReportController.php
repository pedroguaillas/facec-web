<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\InventoryCostOfSalesExport;
use App\Exports\InventoryKardexExport;
use App\Exports\InventoryStockExport;
use App\Exports\InventoryValuationExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Product\Product;
use App\Rules\BranchWarehouseRule;
use App\Services\Product\InventoryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes de inventario y su exportación a Excel. Cada exportación usa los
 * mismos filtros y datos que su reporte JSON, sin paginar.
 */
class InventoryReportController extends Controller
{
    public function __construct(private InventoryReportService $reports) {}

    public function stock(Request $request): JsonResponse
    {
        $filters = $this->stockFilters($request);

        return response()->json($this->reports->stock(
            $filters['search'],
            $filters['warehouse_id'],
            $filters['below_min'],
            (int) ($request->validate(['paginate' => ['nullable', 'integer', 'min:1', 'max:200']])['paginate'] ?? 15),
        ));
    }

    public function stockExport(Request $request): BinaryFileResponse
    {
        $filters = $this->stockFilters($request);

        return Excel::download(
            new InventoryStockExport($this->reports->stockAll($filters['search'], $filters['warehouse_id'], $filters['below_min'])),
            'inventario-stock-'.now()->toDateString().'.xlsx',
        );
    }

    public function valuation(Request $request): JsonResponse
    {
        $filters = $this->valuationFilters($request);

        return response()->json($this->reports->valuation($filters['date'], $filters['warehouse_id']));
    }

    public function valuationExport(Request $request): BinaryFileResponse
    {
        $filters = $this->valuationFilters($request);

        return Excel::download(
            new InventoryValuationExport($this->reports->valuation($filters['date'], $filters['warehouse_id'])),
            "valorizacion-{$filters['date']}.xlsx",
        );
    }

    public function costOfSales(Request $request): JsonResponse
    {
        $filters = $this->costOfSalesFilters($request);

        return response()->json($this->reports->costOfSales($filters['from'], $filters['to'], $filters['warehouse_id']));
    }

    public function costOfSalesExport(Request $request): BinaryFileResponse
    {
        $filters = $this->costOfSalesFilters($request);

        return Excel::download(
            new InventoryCostOfSalesExport($this->reports->costOfSales($filters['from'], $filters['to'], $filters['warehouse_id'])),
            "costo-ventas-{$filters['from']}-{$filters['to']}.xlsx",
        );
    }

    /**
     * Kardex de un producto, en orden de registro.
     */
    public function kardex(Request $request, Product $product): AnonymousResourceCollection|JsonResponse
    {
        if (! $product->isStockable()) {
            return $this->serviceResponse();
        }

        $filters = $this->kardexFilters($request);
        $paginate = (int) ($request->validate(['paginate' => ['nullable', 'integer', 'min:1', 'max:200']])['paginate'] ?? 50);

        $movements = $this->reports->kardexQuery($product, $filters['warehouse_id'], $filters['from'], $filters['to'])
            ->paginate($paginate)
            ->withQueryString();

        return InventoryMovementResource::collection($movements);
    }

    public function kardexExport(Request $request, Product $product): BinaryFileResponse|JsonResponse
    {
        if (! $product->isStockable()) {
            return $this->serviceResponse();
        }

        $filters = $this->kardexFilters($request);
        $movements = $this->reports->kardexQuery($product, $filters['warehouse_id'], $filters['from'], $filters['to'])->get();

        return Excel::download(
            new InventoryKardexExport($product, $movements),
            'kardex-'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $product->code).'.xlsx',
        );
    }

    /**
     * @return array{search: ?string, warehouse_id: ?int, below_min: bool}
     */
    private function stockFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', new BranchWarehouseRule],
            'below_min' => ['nullable', 'boolean'],
        ]);

        return [
            'search' => $validated['search'] ?? null,
            'warehouse_id' => $this->warehouseId($validated),
            'below_min' => $request->boolean('below_min'),
        ];
    }

    /**
     * @return array{date: string, warehouse_id: ?int}
     */
    private function valuationFilters(Request $request): array
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', new BranchWarehouseRule],
        ]);

        return [
            'date' => $validated['date'] ?? now()->toDateString(),
            'warehouse_id' => $this->warehouseId($validated),
        ];
    }

    /**
     * @return array{from: string, to: string, warehouse_id: ?int}
     */
    private function costOfSalesFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'warehouse_id' => ['nullable', 'integer', new BranchWarehouseRule],
        ]);

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'warehouse_id' => $this->warehouseId($validated),
        ];
    }

    /**
     * @return array{warehouse_id: ?int, from: ?string, to: ?string}
     */
    private function kardexFilters(Request $request): array
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', new BranchWarehouseRule],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            'warehouse_id' => $this->warehouseId($validated),
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function warehouseId(array $validated): ?int
    {
        return isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
    }

    private function serviceResponse(): JsonResponse
    {
        return response()->json(['succes' => false, 'message' => 'Los servicios no manejan inventario.'], 422);
    }
}
