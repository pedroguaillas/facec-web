<?php

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\BankAccountStoreRequest;
use App\Http\Requests\Bank\BankAccountUpdateRequest;
use App\Http\Resources\BankAccountResources;
use App\Models\Treasury\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class BankAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginate = (int) $request->input('paginate', 15);

        $accounts = BankAccount::with('bank')
            ->when($request->filled('bank_id'), fn ($query) => $query->where('bank_id', $request->input('bank_id')))
            ->latest()
            ->paginate($paginate)
            ->withQueryString();

        return BankAccountResources::collection($accounts);
    }

    public function store(BankAccountStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $account = BankAccount::create([
            ...$request->validated(),
            'branch_id' => $branch->id,
            'balance' => 0,
        ]);

        return response()->json($account, 201);
    }

    public function edit(BankAccount $bankAccount): JsonResponse
    {
        return response()->json($bankAccount);
    }

    public function update(BankAccountUpdateRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $bankAccount->update($request->validated());

        return response()->json($bankAccount);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        if ((float) $bankAccount->balance !== 0.0) {
            return response()->json([
                'succes' => false,
                'message' => 'No se puede eliminar una cuenta bancaria con saldo distinto de cero.',
            ], 422);
        }

        $bankAccount->delete();

        return response()->json([
            'succes' => true,
            'message' => 'Cuenta bancaria eliminada con éxito.',
        ]);
    }
}
