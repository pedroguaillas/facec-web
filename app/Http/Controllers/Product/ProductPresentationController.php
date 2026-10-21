<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductPresentationStoreRequest;
use App\Http\Resources\ProductPresentationResource;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
use App\Models\Shop\ShopItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductPresentationController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductPresentationResource::collection(
            $product->presentations()->with('product')->get()
        );
    }

    public function store(ProductPresentationStoreRequest $request, Product $product): JsonResponse
    {
        $presentation = $product->presentations()->create($request->validated());

        return response()->json(new ProductPresentationResource($presentation->load('product')), 201);
    }

    public function update(ProductPresentationStoreRequest $request, Product $product, ProductPresentation $presentation): JsonResponse
    {
        abort_unless($presentation->product_id === $product->id, 404);

        $presentation->update($request->validated());

        return response()->json(new ProductPresentationResource($presentation->load('product')));
    }

    public function destroy(Product $product, ProductPresentation $presentation): JsonResponse
    {
        abort_unless($presentation->product_id === $product->id, 404);

        // Las líneas guardan su propio factor, pero sin presentation_id una
        // edición posterior las pasaría a unidad base sin que se note.
        $isUsed = OrderItem::where('presentation_id', $presentation->id)->exists()
            || ShopItem::where('presentation_id', $presentation->id)->exists();

        if ($isUsed) {
            return response()->json([
                'succes' => false,
                'message' => 'La presentación ya se usó en ventas o compras y no se puede eliminar.',
            ], 422);
        }

        $presentation->delete();

        return response()->json(['succes' => true, 'message' => 'Presentación eliminada con éxito.']);
    }
}
