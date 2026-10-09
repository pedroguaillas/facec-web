<?php

namespace App\Services\AccountsReceivable;

use App\Models\AccountsReceivable\CustomerAdvance;
use App\Models\Customer;
use App\Services\SubscriptionGate;
use App\Services\Treasury\MovementService;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registra un anticipo recibido de un cliente, antes de que exista la
 * factura que lo consumirá. Genera el ingreso real en Treasury y el
 * movimiento de cartera (ANTICIPO, haber) en la misma transacción.
 */
class AccountsReceivableAdvanceService
{
    public function __construct(private AccountsReceivableLedgerService $ledgerService) {}

    public function register(Customer $customer, float $amount, string $sourceType, int $sourceId, ?string $date = null): CustomerAdvance
    {
        app(SubscriptionGate::class)->ensureFinancieroAccess(Auth::user()->company);

        $date ??= now()->format('Y-m-d');

        return DB::transaction(function () use ($customer, $amount, $sourceType, $sourceId, $date) {
            $movement = app(MovementService::class)->create([
                'branch_id' => $customer->branch_id,
                'date' => $date,
                'type' => MovementTypes::INCOME,
                'transaction_type' => MovementTransactionTypes::CUSTOMER_ADVANCE,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'amount' => $amount,
                'description' => 'Anticipo recibido de cliente',
                'user_id' => Auth::id(),
            ]);

            $advance = CustomerAdvance::create([
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'movement_id' => $movement->id,
                'amount' => $amount,
                'applied_amount' => 0,
                'date' => $date,
            ]);

            $this->ledgerService->registerAdvance($customer, $amount, $date);

            return $advance;
        });
    }
}
