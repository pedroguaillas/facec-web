<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductStoreRequest;
use App\Http\Requests\Product\ProductUpdateRequest;
use App\Models\Product\IceCataloge;
use App\Models\Product\IvaTax;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

        $products = Product::join('iva_taxes', 'iva_taxes.code', 'products.iva')
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('products.code', 'LIKE', "%{$escaped}%")
                        ->orWhere('products.name', 'LIKE', "%{$escaped}%");
                });
            })
            ->selectRaw('products.id, products.code, products.type_product, products.name, products.price1, iva_taxes.code AS iva_code, iva_taxes.percentage, products.stock')
            ->latest('products.created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('products/Index', [
            'products' => [
                'data' => $products->items(),
                'links' => $products->linkCollection(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/Create', $this->createEditData());
    }

    public function store(ProductStoreRequest $request): RedirectResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $product = $branch->products()->create($request->validated());

        return redirect()
            ->route('products.edit', $product)
            ->with('success', 'Producto creado con éxito.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/Edit', [
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
        ]);
    }

    public function update(ProductUpdateRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('products.edit', $product)
            ->with('success', 'Producto actualizado con éxito.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $product->delete();
        } catch (\Throwable $e) {
            return redirect()
                ->route('products.index')
                ->with('error', 'No se pudo eliminar el producto: '.$e->getMessage());
        }

        return redirect()
            ->route('products.index')
            ->with('success', 'Producto eliminado con éxito.');
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
