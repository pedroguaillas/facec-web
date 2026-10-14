<?php

namespace App\Services\Treasury;

use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\Movement;
use App\StaticClasses\MovementCategories;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resumen de la vista "Movimientos": cuánto entró y salió de cajas/bancos en
 * un período, en qué se fue y el saldo actual de cada cuenta. Ingresos y
 * gastos reales excluyen los movimientos internos y el saldo inicial
 * (MovementTransactionTypes::NON_OPERATING). `income`/`expense`/`net` son
 * solo del negocio; la plata del dueño y los préstamos van en `financing`
 * (no son ganancia ni pérdida) y `cash_change` suma ambos.
 */
class MovementSummaryService
{
    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     income: float,
     *     expense: float,
     *     net: float,
     *     financing: array{owner_contributions: float, owner_withdrawals: float, loans_received: float, loan_payments: float, net: float},
     *     cash_change: float,
     *     internal: float,
     *     owner_withdrawals: float,
     *     pending: array{income: float, expense: float},
     *     by_category: array<int, array{category: string, type: string, group: string, amount: float, count: int}>,
     *     accounts: list<array{type: string, id: int, name: string, balance: float}>
     * }
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?string $partyType = null, ?int $partyId = null): array
    {
        $base = fn (): Builder => Movement::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($partyType && $partyId, fn (Builder $query) => $query->where(function (Builder $q) use ($partyType, $partyId) {
                $q->where(fn (Builder $qq) => $qq->where('source_type', $partyType)->where('source_id', $partyId))
                    ->orWhere(fn (Builder $qq) => $qq->where('destination_type', $partyType)->where('destination_id', $partyId));
            }));

        $operating = fn (string $status): Builder => $base()
            ->where('status', $status)
            ->whereNotIn('transaction_type', MovementTransactionTypes::NON_OPERATING);

        $pending = $operating(MovementStatuses::PENDING)
            ->toBase()
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) AS amount')
            ->pluck('amount', 'type');

        $byCategory = $operating(MovementStatuses::COMPLETED)
            ->toBase()
            ->groupBy('category', 'type')
            ->selectRaw('category, type, SUM(amount) AS amount, COUNT(*) AS movements_count')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row) => [
                'category' => (string) ($row->category ?? MovementCategories::UNCLASSIFIED),
                'type' => (string) $row->type,
                'group' => MovementCategories::groupOf($row->category),
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->movements_count,
            ])
            ->values();

        $business = $byCategory->where('group', MovementCategories::GROUP_BUSINESS);
        $income = round($business->where('type', MovementTypes::INCOME)->sum('amount'), 2);
        $expense = round($business->where('type', MovementTypes::EXPENSE)->sum('amount'), 2);
        $net = round($income - $expense, 2);

        $financingAmount = fn (string $category): float => round($byCategory->where('category', $category)->sum('amount'), 2);
        $financing = [
            'owner_contributions' => $financingAmount(MovementCategories::OWNER_CONTRIBUTION),
            'owner_withdrawals' => $financingAmount(MovementCategories::OWNER_WITHDRAWAL),
            'loans_received' => $financingAmount(MovementCategories::LOAN_RECEIVED),
            'loan_payments' => $financingAmount(MovementCategories::LOAN_PAYMENT),
        ];
        $financing['net'] = round(
            $financing['owner_contributions'] + $financing['loans_received']
            - $financing['owner_withdrawals'] - $financing['loan_payments'],
            2,
        );

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'financing' => $financing,
            'cash_change' => round($net + $financing['net'], 2),
            'internal' => round((float) $base()
                ->where('status', MovementStatuses::COMPLETED)
                ->whereIn('transaction_type', MovementTransactionTypes::INTERNAL)
                ->sum('amount'), 2),
            'owner_withdrawals' => $financing['owner_withdrawals'],
            'pending' => [
                'income' => round((float) ($pending[MovementTypes::INCOME] ?? 0), 2),
                'expense' => round((float) ($pending[MovementTypes::EXPENSE] ?? 0), 2),
            ],
            'by_category' => $byCategory->all(),
            'accounts' => $this->accounts(),
        ];
    }

    /**
     * Saldo actual de cada cuenta bancaria y caja (la caja vale lo de su
     * sesión abierta, 0 si está cerrada).
     *
     * @return list<array{type: string, id: int, name: string, balance: float}>
     */
    private function accounts(): array
    {
        $bankAccounts = BankAccount::with('bank')
            ->orderBy('id')
            ->get()
            ->map(fn (BankAccount $account) => [
                'type' => MovementPartyRegistry::BANK_ACCOUNT,
                'id' => $account->id,
                'name' => trim(($account->bank?->name ?? '').' '.$account->account_number),
                'balance' => round((float) $account->balance, 2),
            ]);

        $cashRegisters = CashRegister::with('activeSession')
            ->orderBy('id')
            ->get()
            ->map(fn (CashRegister $cashRegister) => [
                'type' => MovementPartyRegistry::CASH_REGISTER,
                'id' => $cashRegister->id,
                'name' => (string) $cashRegister->name,
                'balance' => round((float) ($cashRegister->activeSession?->current_balance ?? 0), 2),
            ]);

        return [...$bankAccounts->all(), ...$cashRegisters->all()];
    }
}
