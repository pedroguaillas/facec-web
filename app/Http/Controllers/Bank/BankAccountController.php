<?php

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\BankAccountStoreRequest;
use App\Http\Requests\Bank\BankAccountUpdateRequest;
use App\Http\Resources\BankAccountResources;
use App\Models\Treasury\BankAccount;
use App\Services\Treasury\MovementService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        $openingBalance = (float) ($request->validated('opening_balance') ?? 0);

        $account = DB::transaction(function () use ($request, $branch, $openingBalance) {
            $account = BankAccount::create([
                ...collect($request->validated())->except('opening_balance')->toArray(),
                'branch_id' => $branch->id,
                'balance' => 0,
            ]);

            if ($openingBalance > 0) {
                app(MovementService::class)->create([
                    'branch_id' => $branch->id,
                    'date' => now()->format('Y-m-d'),
                    'type' => MovementTypes::INCOME,
                    'transaction_type' => MovementTransactionTypes::OPENING_BALANCE,
                    'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
                    'source_id' => $account->id,
                    'amount' => $openingBalance,
                    'description' => 'Saldo inicial',
                    'user_id' => Auth::id(),
                ]);
            }

            return $account;
        });

        return response()->json($account->fresh(), 201);
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
