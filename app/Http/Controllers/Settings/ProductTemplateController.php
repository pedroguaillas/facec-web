<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProductTemplateRequest;
use App\Models\Product\ProductTemplate;
use App\Services\Product\ProductTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Plantillas de características de producto que configura el contribuyente.
 * Ver docs/caracteristicas-producto.md.
 */
class ProductTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ProductTemplate::with('fields')->orderBy('name')->get());
    }

    public function store(ProductTemplateRequest $request, ProductTemplateService $service): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $template = $service->save(new ProductTemplate(['branch_id' => $branch->id]), $request->validated());

        return response()->json($template, 201);
    }

    public function show(ProductTemplate $productTemplate): JsonResponse
    {
        return response()->json($productTemplate->load('fields'));
    }

    public function update(ProductTemplateRequest $request, ProductTemplate $productTemplate, ProductTemplateService $service): JsonResponse
    {
        return response()->json($service->save($productTemplate, $request->validated()));
    }

    /**
     * Una plantilla en uso por productos no se elimina: se desactiva.
     */
    public function destroy(ProductTemplate $productTemplate): JsonResponse
    {
        if ($productTemplate->products()->withTrashed()->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'La plantilla está asignada a productos y no se puede eliminar; desactívala.',
            ], 422);
        }

        $productTemplate->delete();

        return response()->json(['succes' => true, 'message' => 'Plantilla eliminada con éxito.']);
    }
}
