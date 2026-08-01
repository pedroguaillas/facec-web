<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerStoreRequest;
use App\Http\Requests\Customer\CustomerUpdateRequest;
use App\Http\Resources\CustomerResources;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $customers = Customer::query()
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

        return CustomerResources::collection($customers)->additional(['succes' => true]);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function store(CustomerStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $customer = $branch->customers()->create($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Cliente creado con éxito.',
            'data' => $customer,
        ], 201);
    }

    public function edit(Customer $customer): JsonResponse
    {
        return response()->json([
            'succes' => true,
            'customer' => [
                'id' => $customer->id,
                'type_identification' => $customer->type_identification,
                'identication' => $customer->identication,
                'name' => $customer->name,
                'address' => $customer->address,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
        ], 200);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Cliente actualizado con éxito.',
            'data' => $customer,
        ], 200);
    }
}
