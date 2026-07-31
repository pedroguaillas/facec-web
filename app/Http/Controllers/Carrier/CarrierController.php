<?php

namespace App\Http\Controllers\Carrier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carrier\CarrierStoreRequest;
use App\Http\Requests\Carrier\CarrierUpdateRequest;
use App\Models\Carrier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CarrierController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

        $carriers = Carrier::when($search, function ($query) use ($search) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

            $query->where(function ($q) use ($escaped) {
                $q->where('identication', 'LIKE', "%{$escaped}%")
                    ->orWhere('name', 'LIKE', "%{$escaped}%");
            });
        })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('carriers/Index', [
            'carriers' => [
                'data' => $carriers->items(),
                'links' => $carriers->linkCollection(),
                'meta' => [
                    'current_page' => $carriers->currentPage(),
                    'last_page' => $carriers->lastPage(),
                    'per_page' => $carriers->perPage(),
                    'total' => $carriers->total(),
                    'from' => $carriers->firstItem(),
                    'to' => $carriers->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('carriers/Create');
    }

    public function store(CarrierStoreRequest $request): RedirectResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $carrier = $branch->carriers()->create($request->validated());

        return redirect()
            ->route('carriers.edit', $carrier)
            ->with('success', 'Transportista creado con éxito.');
    }

    public function edit(Carrier $carrier): Response
    {
        return Inertia::render('carriers/Edit', [
            'carrier' => [
                'id' => $carrier->id,
                'type_identification' => $carrier->type_identification,
                'identication' => $carrier->identication,
                'name' => $carrier->name,
                'license_plate' => $carrier->license_plate,
                'email' => $carrier->email,
            ],
        ]);
    }

    public function update(CarrierUpdateRequest $request, Carrier $carrier): RedirectResponse
    {
        $carrier->update($request->validated());

        return redirect()
            ->route('carriers.edit', $carrier)
            ->with('success', 'Transportista actualizado con éxito.');
    }
}
