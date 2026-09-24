<?php

namespace App\Http\Controllers\CashRegister;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashRegister\CashRegisterStoreRequest;
use App\Http\Requests\CashRegister\CashRegisterUpdateRequest;
use App\Http\Resources\CashRegisterResources;
use App\Models\Treasury\CashRegister;
use App\StaticClasses\CashRegisterStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class CashRegisterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginate = (int) $request->input('paginate', 15);

        $cashRegisters = CashRegister::with(['activeSession', 'responsible'])
            ->latest()
            ->paginate($paginate)
            ->withQueryString();

        return CashRegisterResources::collection($cashRegisters);
    }

    public function store(CashRegisterStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $cashRegister = CashRegister::create([
            ...$request->validated(),
            'branch_id' => $branch->id,
            'status' => CashRegisterStatuses::CLOSED,
        ]);

        return response()->json($cashRegister, 201);
    }

    public function edit(CashRegister $cashRegister): JsonResponse
    {
        return response()->json($cashRegister->load('activeSession'));
    }

    public function update(CashRegisterUpdateRequest $request, CashRegister $cashRegister): JsonResponse
    {
        $cashRegister->update($request->validated());

        return response()->json($cashRegister->load('activeSession'));
    }
}
