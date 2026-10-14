<?php

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Http\Requests\Treasury\MovementCategoryRequest;
use App\Http\Requests\Treasury\MovementDepositRequest;
use App\Http\Requests\Treasury\MovementOtherRequest;
use App\Http\Requests\Treasury\MovementSummaryRequest;
use App\Http\Requests\Treasury\MovementTransferRequest;
use App\Http\Requests\Treasury\MovementWithdrawRequest;
use App\Http\Resources\MovementResources;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\Movement;
use App\Services\SubscriptionGate;
use App\Services\Treasury\BankTransferService;
use App\Services\Treasury\DepositService;
use App\Services\Treasury\MovementService;
use App\Services\Treasury\MovementSummaryService;
use App\Services\Treasury\WithdrawalService;
use App\StaticClasses\MovementCategories;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class MovementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginate = (int) $request->input('paginate', 15);

        $movements = Movement::with(['source', 'destination', 'origin'])
            ->when(
                $request->filled('source_type') && $request->filled('source_id'),
                fn ($query) => $query->where('source_type', $request->input('source_type'))
                    ->where('source_id', $request->input('source_id')),
            )
            ->when(
                // Movimientos donde esta cuenta/sesión participa como origen O destino
                // (ej. un DEPOSITO tiene la sesión de caja como origen; un RETIRO la
                // tiene como destino — filtrar solo por source se pierde la mitad).
                $request->filled('party_type') && $request->filled('party_id'),
                fn ($query) => $query->where(function ($q) use ($request) {
                    $q->where(function ($qq) use ($request) {
                        $qq->where('source_type', $request->input('party_type'))
                            ->where('source_id', $request->input('party_id'));
                    })->orWhere(function ($qq) use ($request) {
                        $qq->where('destination_type', $request->input('party_type'))
                            ->where('destination_id', $request->input('party_id'));
                    });
                }),
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('date', '<=', $request->input('date_to')))
            ->when(
                // Entradas/salidas reales de la empresa: los movimientos internos
                // (depósito, retiro, transferencia, apertura/cierre de caja) y el
                // saldo inicial no son ni una ni otra.
                in_array($request->input('direction'), ['in', 'out'], true),
                fn ($query) => $query
                    ->where('type', $request->input('direction') === 'in' ? MovementTypes::INCOME : MovementTypes::EXPENSE)
                    ->whereNotIn('transaction_type', MovementTransactionTypes::NON_OPERATING),
            )
            ->when(
                $request->filled('transaction_type'),
                fn ($query) => $query->whereIn('transaction_type', explode(',', (string) $request->input('transaction_type'))),
            )
            ->when($request->filled('category'), fn ($query) => $request->input('category') === MovementCategories::UNCLASSIFIED
                ? $query->whereNull('category')
                : $query->where('category', $request->input('category')))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($paginate)
            ->withQueryString();

        return MovementResources::collection($movements);
    }

    public function show(Movement $movement): JsonResponse
    {
        return response()->json(new MovementResources($movement->load(['source', 'destination', 'origin'])));
    }

    /**
     * Catálogo fijo de categorías: tipo de movimiento que la admite y grupo
     * (NEGOCIO / FINANCIAMIENTO).
     */
    public function categories(): JsonResponse
    {
        return response()->json(MovementCategories::catalog());
    }

    /**
     * Resumen de la vista "Movimientos": solo planes con financiero.
     */
    public function summary(MovementSummaryRequest $request, SubscriptionGate $gate, MovementSummaryService $service): JsonResponse
    {
        $gate->ensureFinancieroAccess(Auth::user()->company);

        $from = $request->filled('date_from')
            ? CarbonImmutable::parse($request->validated('date_from'))
            : CarbonImmutable::now()->startOfMonth();
        $to = $request->filled('date_to')
            ? CarbonImmutable::parse($request->validated('date_to'))
            : $from->endOfMonth();

        return response()->json($service->summary(
            $from,
            $to,
            $request->validated('party_type'),
            $request->filled('party_id') ? (int) $request->validated('party_id') : null,
        ));
    }

    /**
     * Clasifica un movimiento suelto (OTRO); los demás tienen categoría
     * automática o son internos.
     */
    public function updateCategory(MovementCategoryRequest $request, Movement $movement): JsonResponse
    {
        if ($movement->transaction_type !== MovementTransactionTypes::OTHER) {
            return response()->json(['succes' => false, 'message' => 'Solo se puede clasificar un movimiento manual (OTRO).'], 422);
        }

        $movement->update(['category' => $request->validated('category')]);

        return response()->json(new MovementResources($movement->load(['source', 'destination', 'origin'])));
    }

    public function deposit(MovementDepositRequest $request, DepositService $service): JsonResponse
    {
        $session = CashRegisterSession::findOrFail($request->validated('cash_register_session_id'));
        $bankAccount = BankAccount::findOrFail($request->validated('bank_account_id'));

        try {
            $movement = $service->deposit(
                $session,
                $bankAccount,
                (float) $request->validated('amount'),
                Auth::user(),
                $this->extra($request),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new MovementResources($movement->load(['source', 'destination'])), 201);
    }

    public function withdraw(MovementWithdrawRequest $request, WithdrawalService $service): JsonResponse
    {
        $bankAccount = BankAccount::findOrFail($request->validated('bank_account_id'));
        $session = CashRegisterSession::findOrFail($request->validated('cash_register_session_id'));

        try {
            $movement = $service->withdraw(
                $bankAccount,
                $session,
                (float) $request->validated('amount'),
                Auth::user(),
                $this->extra($request),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new MovementResources($movement->load(['source', 'destination'])), 201);
    }

    public function transfer(MovementTransferRequest $request, BankTransferService $service): JsonResponse
    {
        $origin = BankAccount::findOrFail($request->validated('origin_bank_account_id'));
        $destination = BankAccount::findOrFail($request->validated('destination_bank_account_id'));

        try {
            $movement = $service->transfer(
                $origin,
                $destination,
                (float) $request->validated('amount'),
                Auth::user(),
                $this->extra($request),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new MovementResources($movement->load(['source', 'destination'])), 201);
    }

    public function other(MovementOtherRequest $request, MovementService $service): JsonResponse
    {
        $branchId = $request->validated('source_type') === MovementPartyRegistry::BANK_ACCOUNT
            ? BankAccount::findOrFail($request->validated('source_id'))->branch_id
            : CashRegisterSession::findOrFail($request->validated('source_id'))->branch_id;

        try {
            $movement = $service->create([
                ...$this->extra($request),
                'branch_id' => $branchId,
                'type' => $request->validated('type'),
                'transaction_type' => MovementTransactionTypes::OTHER,
                'category' => $request->validated('category'),
                'source_type' => $request->validated('source_type'),
                'source_id' => $request->validated('source_id'),
                'amount' => (float) $request->validated('amount'),
                'user_id' => Auth::id(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new MovementResources($movement->load(['source', 'destination'])), 201);
    }

    public function complete(Movement $movement, MovementService $service): JsonResponse
    {
        try {
            $movement = $service->complete($movement);
        } catch (\RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new MovementResources($movement->load(['source', 'destination'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function extra(Request $request): array
    {
        return [
            'date' => $request->input('date', now()->format('Y-m-d')),
            'payment_method' => $request->input('payment_method'),
            'receipt_number' => $request->input('receipt_number'),
            'description' => $request->input('description'),
            'receipt_path' => $request->hasFile('receipt') ? $request->file('receipt')->store('treasury/receipts') : null,
        ];
    }
}
