<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerStoreRequest;
use App\Http\Requests\Customer\CustomerUpdateRequest;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

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
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => [
                'data' => $customers->items(),
                'links' => $customers->linkCollection(),
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                    'from' => $customers->firstItem(),
                    'to' => $customers->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customers/Create');
    }

    public function store(CustomerStoreRequest $request): RedirectResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $customer = $branch->customers()->create($request->validated());

        return redirect()
            ->route('customers.edit', $customer)
            ->with('success', 'Cliente creado con éxito.');
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'type_identification' => $customer->type_identification,
                'identication' => $customer->identication,
                'name' => $customer->name,
                'address' => $customer->address,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
        ]);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()
            ->route('customers.edit', $customer)
            ->with('success', 'Cliente actualizado con éxito.');
    }
}
