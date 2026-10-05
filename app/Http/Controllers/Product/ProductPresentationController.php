<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductPresentationStoreRequest;
use App\Http\Resources\ProductPresentationResource;
use App\Models\Product\Product;
use App\Models\Product\ProductPresentation;
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

        $presentation->delete();

        return response()->json(['succes' => true, 'message' => 'Presentación eliminada con éxito.']);
    }
}
