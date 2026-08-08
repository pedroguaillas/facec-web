<?php

namespace App\Http\Controllers\Carrier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\CarrierStoreRequest;
use App\Http\Requests\Carrier\CarrierUpdateRequest;
use App\Http\Resources\CarrierResources;
use App\Models\Carrier;
use App\Services\SriResolveNameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class CarrierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $carriers = Carrier::when($search, function ($query) use ($search) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

            $query->where(function ($q) use ($escaped) {
                $q->where('identication', 'LIKE', "%{$escaped}%")
                    ->orWhere('name', 'LIKE', "%{$escaped}%");
            });
        })
            ->latest()
            ->paginate($paginate)
            ->withQueryString();

        return CarrierResources::collection($carriers);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function resolve(string $identification, SriResolveNameService $sriService): JsonResponse
    {
        $carrier = Carrier::where('identication', $identification)->first();

        if (! $carrier) {
            $carrierModel = Carrier::withoutGlobalScope('branch')
                ->where('identication', $identification)
                ->latest()
                ->first();

            $sriData = strlen($identification) === 13
                ? $sriService->searchByIdentificationSRI($identification)
                : [];

            $modelAttributes = $carrierModel ? $carrierModel->toArray() : [];

            $carrier = array_merge(
                $modelAttributes,
                $sriData,
                ['branch_id' => 0]
            );
        }

        return response()->json($carrier);
    }

    public function store(CarrierStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $carrier = $branch->carriers()->create($request->validated());

        return response()->json($carrier);
    }

    public function edit(Carrier $carrier): JsonResponse
    {
        return response()->json($carrier);
    }

    public function update(CarrierUpdateRequest $request, Carrier $carrier): JsonResponse
    {
        $carrier->update($request->validated());

        return response()->json($carrier);
    }

    public function destroy(Carrier $carrier): JsonResponse
    {
        $isUsed = $carrier->referralGuides()->exists();

        try {
            $isUsed ? $carrier->delete() : $carrier->forceDelete();
        } catch (\Throwable $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'message' => 'Transportista eliminado con éxito.',
        ]);
    }
}
