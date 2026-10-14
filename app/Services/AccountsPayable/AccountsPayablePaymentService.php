<?php

namespace App\Services\AccountsPayable;

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Shop\Shop;
use App\Services\Treasury\MovementService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Abono/pago posterior de la empresa a un proveedor contra una compra a
 * crédito ya registrada. Genera el egreso real en Treasury (caja/banco) y el
 * movimiento de cartera en la misma transacción.
 */
class AccountsPayablePaymentService
{
    public function __construct(private AccountsPayableLedgerService $ledgerService) {}

    public function pay(Shop $shop, float $amount, string $sourceType, int $sourceId, ?string $date = null): AccountPayableMovement
    {
        if ($shop->payment_type !== PaymentTypes::CREDITO) {
            throw new \RuntimeException('Esta compra no está a crédito, no admite abonos.');
        }

        $pendiente = (float) $shop->total - (float) $shop->paid;

        if ($amount <= 0 || $amount > $pendiente) {
            throw new \RuntimeException('El abono no puede ser mayor al saldo pendiente de la compra.');
        }

        return DB::transaction(function () use ($shop, $amount, $sourceType, $sourceId, $date) {
            app(MovementService::class)->create([
                'branch_id' => $shop->branch_id,
                'date' => $date ?? now()->format('Y-m-d'),
                'type' => MovementTypes::EXPENSE,
                'transaction_type' => MovementTransactionTypes::PURCHASE_PAYMENT,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'origin_type' => MovementPartyRegistry::SHOP,
                'origin_id' => $shop->id,
                'amount' => $amount,
                'description' => "Abono compra {$shop->serie}",
                'user_id' => Auth::id(),
            ]);

            $shop->increment('paid', $amount);

            return $this->ledgerService->registerPayment($shop, $amount, $date);
        });
    }
}
