<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerStoreRequest;
use App\Http\Requests\Customer\CustomerUpdateRequest;
use App\Http\Resources\CustomerResources;
use App\Models\Customer;
use App\Services\SriResolveNameService;
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

        return CustomerResources::collection($customers);
    }

    public function create(): JsonResponse
    {
        return response()->json(['succes' => true], 200);
    }

    public function resolve(string $identification, SriResolveNameService $sriService): JsonResponse
    {
        $customer = Customer::where('identication', $identification)->first();

        if (! $customer) {
            $customerModel = Customer::withoutGlobalScope('branch')
                ->where('identication', $identification)
                ->latest()
                ->first();

            $sriData = strlen($identification) === 13
                ? $sriService->searchByIdentificationSRI($identification)
                : [];

            $modelAttributes = $customerModel ? $customerModel->toArray() : [];

            $customer = array_merge(
                $modelAttributes,
                $sriData,
                ['branch_id' => 0]
            );
        }

        return response()->json($customer);
    }

    public function store(CustomerStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $customer = $branch->customers()->create($request->validated());

        return response()->json($customer);
    }

    public function edit(Customer $customer): JsonResponse
    {
        return response()->json($customer);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return response()->json($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $isUsed = $customer->orders()->exists()
            || $customer->referralGuides()->exists();

        try {
            $isUsed ? $customer->delete() : $customer->forceDelete();
        } catch (\Throwable $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'message' => 'Cliente eliminado con éxito.',
        ]);
    }
}
