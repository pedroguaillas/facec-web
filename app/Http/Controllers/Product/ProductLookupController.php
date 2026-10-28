<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductLookupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        // También encuentra por el código propio de una presentación activa (ej. código de barras de la cubeta).
        $products = Product::query()
            ->where(function ($query) use ($term) {
                $query->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhereHas('presentations', fn ($presentation) => $presentation->where('active', true)->where('code', 'like', "%{$term}%"))
                    // Unidad por chasis/motor/etc.: valor de una característica única.
                    ->orWhereHas('details', fn ($detail) => $detail->where('value', 'like', "%{$term}%")
                        ->whereHas('field', fn ($field) => $field->where('unique', true)->where('active', true)));
            })
            ->with([
                'presentations' => fn ($presentation) => $presentation->where('active', true)->select('id', 'product_id', 'name', 'conversion_factor', 'price', 'code'),
                'details.field',
                'parentProduct.details.field',
            ])
            ->limit(20)
            ->get(['id', 'parent_product_id', 'code', 'type_product', 'name', 'price1', 'stock', 'iva', 'ice'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'code' => $product->code,
                'type_product' => $product->type_product,
                'name' => $product->name,
                'price' => $product->price1,
                'stock' => $product->stock,
                'iva' => $product->iva,
                'ice' => $product->ice,
                // Características efectivas (modelo + unidad); vacío si el producto no usa plantilla.
                'characteristics' => $product->characteristics(),
                // Precio propio o, si no tiene, precio de la unidad × factor.
                'presentations' => $product->presentations->map(fn ($presentation) => [
                    'id' => $presentation->id,
                    'name' => $presentation->name,
                    'code' => $presentation->code,
                    'conversion_factor' => $presentation->conversion_factor,
                    'price' => $presentation->price ?? round((float) $product->price1 * $presentation->conversion_factor, 6),
                ])->values(),
            ]);

        return response()->json($products);
    }
}
