<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductUnitRequest;
use App\Models\Product\Product;
use App\Services\Product\ProductDetailService;
use App\Services\Product\ProductUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Unidades físicas (variantes) de un producto modelo con plantilla
 * (ej. cada moto con su chasis). Ver docs/caracteristicas-producto.md.
 */
class ProductUnitController extends Controller
{
    public function __construct(private ProductDetailService $details) {}

    public function index(Product $product): JsonResponse
    {
        $units = $product->variants()->with(['details.field', 'parentProduct.details.field'])->orderBy('code')->get();

        return response()->json($units->map(fn (Product $unit) => $this->present($unit))->values());
    }

    public function store(ProductUnitRequest $request, Product $product, ProductUnitService $service): JsonResponse
    {
        $unit = $service->create(
            $product,
            $request->validated('details'),
            $request->unitCode(),
            inventoryEnabled: (bool) Auth::user()->company->inventory,
        );

        return response()->json($this->present($unit->fresh()), 201);
    }

    public function update(ProductUnitRequest $request, Product $product, Product $unit, ProductUnitService $service): JsonResponse
    {
        abort_unless($unit->parent_product_id === $product->id, 404);

        $service->update($unit, $request->validated('details'), $request->unitCode());

        return response()->json($this->present($unit->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $unit): array
    {
        return [
            'id' => $unit->id,
            'parent_product_id' => $unit->parent_product_id,
            'code' => $unit->code,
            'name' => $unit->name,
            'price1' => $unit->price1,
            'stock' => $unit->stock,
            'details' => $this->details->valuesOf($unit),
            'characteristics' => $unit->characteristics(),
        ];
    }
}
