<?php

namespace App\Http\Controllers\Carrier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\CarrierStoreRequest;
use App\Http\Requests\Carrier\CarrierUpdateRequest;
use App\Http\Resources\CarrierResources;
use App\Models\Carrier;
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

        return CarrierResources::collection($carriers)->additional(['succes' => true]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function store(CarrierStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $carrier = $branch->carriers()->create($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Transportista creado con éxito.',
            'data' => $carrier,
        ], 201);
    }

    public function edit(Carrier $carrier): JsonResponse
    {
        return response()->json([
            'succes' => true,
            'carrier' => [
                'id' => $carrier->id,
                'type_identification' => $carrier->type_identification,
                'identication' => $carrier->identication,
                'name' => $carrier->name,
                'license_plate' => $carrier->license_plate,
                'email' => $carrier->email,
            ],
        ], 200);
    }

    public function update(CarrierUpdateRequest $request, Carrier $carrier): JsonResponse
    {
        $carrier->update($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Transportista actualizado con éxito.',
            'data' => $carrier,
        ], 200);
    }
}
