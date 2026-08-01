<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductStoreRequest;
use App\Http\Requests\Product\ProductUpdateRequest;
use App\Http\Resources\ProductResources;
use App\Models\Product\IceCataloge;
use App\Models\Product\IvaTax;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $products = Product::join('iva_taxes', 'iva_taxes.code', 'products.iva')
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('products.code', 'LIKE', "%{$escaped}%")
                        ->orWhere('products.name', 'LIKE', "%{$escaped}%");
                });
            })
            ->selectRaw('products.id, products.code, products.type_product, products.name, products.price1, iva_taxes.code AS iva_code, iva_taxes.percentage, products.ice, products.irbpnr, products.stock, products.tourism')
            ->latest('products.created_at')
            ->paginate($paginate)
            ->withQueryString();

        return ProductResources::collection($products)->additional(['succes' => true]);
    }

    public function create(): JsonResponse
    {
        return response()->json([
            'succes' => true,
            ...$this->createEditData(),
        ], 200);
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $product = $branch->products()->create($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Producto creado con éxito.',
            'data' => $product,
        ], 201);
    }

    public function edit(Product $product): JsonResponse
    {
        return response()->json([
            'succes' => true,
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'type_product' => $product->type_product,
                'name' => $product->name,
                'price1' => $product->price1,
                'iva' => $product->iva,
                'ice' => $product->ice,
                'aux_cod' => $product->aux_cod,
                'stock' => $product->stock,
            ],
            ...$this->createEditData(),
        ], 200);
    }

    public function update(ProductUpdateRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Producto actualizado con éxito.',
            'data' => $product,
        ], 200);
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            $product->delete();
        } catch (\Throwable $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'message' => 'Producto eliminado con éxito.',
        ]);
    }

    /**
     * @return array{ivaTaxes: Collection, iceCataloges: Collection|array, sriCategories: Collection|array, transport: bool}
     */
    private function createEditData(): array
    {
        $company = Auth::user()->company;

        return [
            'ivaTaxes' => IvaTax::query()
                ->where('state', 'active')
                ->when(! $company->base5, fn ($q) => $q->where('code', '<>', 5))
                ->get(['code', 'percentage']),
            'iceCataloges' => $company->ice ? IceCataloge::get(['code', 'description']) : [],
            'sriCategories' => ($company->transport || $company->base5)
                ? SriCategory::get(['code', 'description', 'type'])
                : [],
            'transport' => $company->transport,
        ];
    }
}
