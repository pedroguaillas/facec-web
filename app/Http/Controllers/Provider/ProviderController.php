<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ProviderStoreRequest;
use App\Http\Requests\Provider\ProviderUpdateRequest;
use App\Http\Resources\ProviderResources;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class ProviderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $providers = Provider::query()
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('identication', 'LIKE', "%{$escaped}%")
                        ->orWhere('name', 'LIKE', "%{$escaped}%");
                });
            })
            ->select('id', 'type_identification', 'identication', 'name', 'address', 'phone', 'email')
            ->latest('created_at')
            ->paginate($paginate)
            ->withQueryString();

        return ProviderResources::collection($providers)->additional(['succes' => true]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function store(ProviderStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $provider = $branch->providers()->create($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Proveedor creado con éxito.',
            'data' => $provider,
        ], 201);
    }

    public function edit(Provider $provider): JsonResponse
    {
        return response()->json([
            'succes' => true,
            'provider' => [
                'id' => $provider->id,
                'type_identification' => $provider->type_identification,
                'identication' => $provider->identication,
                'name' => $provider->name,
                'address' => $provider->address,
                'phone' => $provider->phone,
                'email' => $provider->email,
            ],
        ], 200);
    }

    public function update(ProviderUpdateRequest $request, Provider $provider): JsonResponse
    {
        $provider->update($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Proveedor actualizado con éxito.',
            'data' => $provider,
        ], 200);
    }
}
