<?php

namespace App\Http\Controllers\Product;

use App\Exports\ProductExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductImportRequest;
use App\Http\Requests\Product\ProductStoreRequest;
use App\Http\Requests\Product\ProductUpdateRequest;
use App\Http\Resources\ProductResources;
use App\Imports\ProductsImport;
use App\Models\Company;
use App\Models\Product\IceCataloge;
use App\Models\Product\IvaTax;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->ofType((int) $request->input('type'));
            })
            ->selectRaw('products.id, products.code, products.aux_cod, products.type_product, products.name, products.price1, iva_taxes.code AS iva_code, iva_taxes.percentage, products.ice, products.irbpnr, products.stock, products.tourism')
            ->latest('products.created_at')
            ->paginate($paginate)
            ->withQueryString();

        return ProductResources::collection($products);
    }

    public function create(): JsonResponse
    {
        return response()->json([
            ...$this->createEditData(),
        ], 200);
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $product = $branch->products()->create($request->validated());

        return response()->json($product, 201);
    }

    public function edit(Product $product): JsonResponse
    {
        return response()->json([
            'product' => $product,
            ...$this->createEditData(),
        ], 200);
    }

    public function update(ProductUpdateRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        return response()->json($product);
    }

    public function export(): BinaryFileResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        return Excel::download(new ProductExport($branch->id), 'productos.xlsx');
    }

    public function import(ProductImportRequest $request): JsonResponse
    {
        $company = Auth::user()->company;
        $branch = $company->branches()->orderBy('created_at')->first();

        $import = new ProductsImport($branch->id);

        // El navegador puede subir el archivo con una extensión de filename
        // engañosa (p. ej. .csv) aunque el contenido real sea xlsx; el
        // detector de maatwebsite confía en esa extensión, así que forzamos
        // el tipo según el contenido real del archivo (MIME sniffing).
        $readerType = strtolower($request->file('file')->guessExtension() ?? '') === 'xls'
            ? ExcelFormat::XLS
            : ExcelFormat::XLSX;

        Excel::import($import, $request->file('file'), null, $readerType);

        return response()->json([
            'success' => $import->failures()->isEmpty(),
            'failures' => $import->failures()->map(fn ($failure) => [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
            ])->values(),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $isUsed = $product->orderItems()->exists()
            || $product->shopItems()->exists()
            || $product->referralGuideItems()->exists()
            || $product->inventories()->exists();

        try {
            $isUsed ? $product->delete() : $product->forceDelete();
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
                ->selectRaw("code AS value, CONCAT(percentage, '%') AS label")
                ->get(),
            'iceCataloges' => $company->ice ? IceCataloge::get(['code', 'description']) : [],
            'sriCategories' => $this->sriCategoriesFor($company),
            'transport' => $company->transport,
        ];
    }

    /**
     * @return Collection|array<int, mixed>
     */
    private function sriCategoriesFor(Company $company): Collection|array
    {
        $types = array_filter([
            $company->transport ? 'transporte' : null,
            $company->base5 ? 'ferreteria' : null,
        ]);

        if ($types === []) {
            return [];
        }

        return SriCategory::whereIn('type', $types)->get(['code', 'description', 'type']);
    }
}
