<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Provider\ProviderStoreRequest;
use App\Http\Requests\Provider\ProviderUpdateRequest;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

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
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('providers/Index', [
            'providers' => [
                'data' => $providers->items(),
                'links' => $providers->linkCollection(),
                'meta' => [
                    'current_page' => $providers->currentPage(),
                    'last_page' => $providers->lastPage(),
                    'per_page' => $providers->perPage(),
                    'total' => $providers->total(),
                    'from' => $providers->firstItem(),
                    'to' => $providers->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('providers/Create');
    }

    public function store(ProviderStoreRequest $request): RedirectResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $provider = $branch->providers()->create($request->validated());

        return redirect()
            ->route('providers.edit', $provider)
            ->with('success', 'Proveedor creado con éxito.');
    }

    public function edit(Provider $provider): Response
    {
        return Inertia::render('providers/Edit', [
            'provider' => [
                'id' => $provider->id,
                'type_identification' => $provider->type_identification,
                'identication' => $provider->identication,
                'name' => $provider->name,
                'address' => $provider->address,
                'phone' => $provider->phone,
                'email' => $provider->email,
            ],
        ]);
    }

    public function update(ProviderUpdateRequest $request, Provider $provider): RedirectResponse
    {
        $provider->update($request->validated());

        return redirect()
            ->route('providers.edit', $provider)
            ->with('success', 'Proveedor actualizado con éxito.');
    }
}
