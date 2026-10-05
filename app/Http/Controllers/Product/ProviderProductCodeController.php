<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ProviderProductCodeStoreRequest;
use App\Models\Product\ProviderProductCode;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProviderProductCodeController extends Controller
{
    /**
     * Lista los códigos del proveedor; con `?code=` resuelve uno exacto
     * (autocompletar el producto al registrar una compra).
     */
    public function index(Request $request, Provider $provider): JsonResponse
    {
        $codes = ProviderProductCode::with('product')
            ->where('provider_id', $provider->id)
            ->when($request->filled('code'), fn ($query) => $query->where('code', $request->input('code')))
            ->get()
            ->map(fn (ProviderProductCode $code) => $this->transform($code));

        return response()->json($codes);
    }

    public function store(ProviderProductCodeStoreRequest $request, Provider $provider): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $code = ProviderProductCode::create([
            'branch_id' => $branch->id,
            'provider_id' => $provider->id,
            'product_id' => $request->validated('product_id'),
            'code' => $request->validated('code'),
        ]);

        return response()->json($this->transform($code->load('product')), 201);
    }

    public function destroy(Provider $provider, ProviderProductCode $productCode): JsonResponse
    {
        abort_unless($productCode->provider_id === $provider->id, 404);

        $productCode->delete();

        return response()->json(['succes' => true, 'message' => 'Código eliminado con éxito.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(ProviderProductCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'product_id' => $code->product_id,
            'product_name' => $code->product?->name,
            'product_code' => $code->product?->code,
        ];
    }
}
