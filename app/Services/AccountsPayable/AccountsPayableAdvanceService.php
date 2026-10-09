<?php

namespace App\Services\AccountsPayable;

use App\Models\AccountsPayable\ProviderAdvance;
use App\Models\Provider;
use App\Services\SubscriptionGate;
use App\Services\Treasury\MovementService;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registra un anticipo pagado a un proveedor, antes de que exista la compra
 * que lo consumirá. Genera el egreso real en Treasury y el movimiento de
 * cartera (ANTICIPO, debe) en la misma transacción.
 */
class AccountsPayableAdvanceService
{
    public function __construct(private AccountsPayableLedgerService $ledgerService) {}

    public function register(Provider $provider, float $amount, string $sourceType, int $sourceId, ?string $date = null): ProviderAdvance
    {
        app(SubscriptionGate::class)->ensureFinancieroAccess(Auth::user()->company);

        $date ??= now()->format('Y-m-d');

        return DB::transaction(function () use ($provider, $amount, $sourceType, $sourceId, $date) {
            $movement = app(MovementService::class)->create([
                'branch_id' => $provider->branch_id,
                'date' => $date,
                'type' => MovementTypes::EXPENSE,
                'transaction_type' => MovementTransactionTypes::PROVIDER_ADVANCE,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'amount' => $amount,
                'description' => 'Anticipo pagado a proveedor',
                'user_id' => Auth::id(),
            ]);

            $advance = ProviderAdvance::create([
                'branch_id' => $provider->branch_id,
                'provider_id' => $provider->id,
                'movement_id' => $movement->id,
                'amount' => $amount,
                'applied_amount' => 0,
                'date' => $date,
            ]);

            $this->ledgerService->registerAdvance($provider, $amount, $date);

            return $advance;
        });
    }
}
