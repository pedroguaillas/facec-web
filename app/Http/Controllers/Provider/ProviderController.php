<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ProviderStoreRequest;
use App\Http\Requests\Provider\ProviderUpdateRequest;
use App\Http\Resources\ProviderResources;
use App\Models\Provider;
use App\Services\SriResolveNameService;
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

        return ProviderResources::collection($providers);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function resolve(string $identification, SriResolveNameService $sriService): JsonResponse
    {
        $provider = Provider::where('identication', $identification)->first();

        if (! $provider) {
            $providerModel = Provider::withoutGlobalScope('branch')
                ->where('identication', $identification)
                ->latest()
                ->first();

            $sriData = strlen($identification) === 13
                ? $sriService->searchByIdentificationSRI($identification)
                : [];

            $modelAttributes = $providerModel ? $providerModel->toArray() : [];

            $provider = array_merge(
                $modelAttributes,
                $sriData,
                ['branch_id' => 0]
            );
        }

        return response()->json($provider);
    }

    public function store(ProviderStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $provider = $branch->providers()->create($request->validated());

        return response()->json($provider, 201);
    }

    public function edit(Provider $provider): JsonResponse
    {
        return response()->json($provider);
    }

    public function update(ProviderUpdateRequest $request, Provider $provider): JsonResponse
    {
        $provider->update($request->validated());

        return response()->json($provider);
    }

    public function destroy(Provider $provider): JsonResponse
    {
        $isUsed = $provider->shops()->exists();

        try {
            $isUsed ? $provider->delete() : $provider->forceDelete();
        } catch (\Throwable $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'message' => 'Proveedor eliminado con éxito.',
        ]);
    }
}
