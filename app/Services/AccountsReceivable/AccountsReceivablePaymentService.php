<?php

namespace App\Services\AccountsReceivable;

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Order\Order;
use App\Services\Treasury\MovementService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Abono/pago posterior de un cliente contra una factura a crédito ya
 * registrada. Genera el ingreso real en Treasury (caja/banco) y el
 * movimiento de cartera en la misma transacción.
 */
class AccountsReceivablePaymentService
{
    public function __construct(private AccountsReceivableLedgerService $ledgerService) {}

    public function pay(Order $order, float $amount, string $sourceType, int $sourceId, ?string $date = null): AccountReceivableMovement
    {
        if ($order->payment_type !== PaymentTypes::CREDITO) {
            throw new \RuntimeException('Esta venta no está a crédito, no admite abonos.');
        }

        $pendiente = (float) $order->total - (float) $order->paid;

        if ($amount <= 0 || $amount > $pendiente) {
            throw new \RuntimeException('El abono no puede ser mayor al saldo pendiente de la factura.');
        }

        return DB::transaction(function () use ($order, $amount, $sourceType, $sourceId, $date) {
            app(MovementService::class)->create([
                'branch_id' => $order->branch_id,
                'date' => $date ?? now()->format('Y-m-d'),
                'type' => MovementTypes::INCOME,
                'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'origin_type' => MovementPartyRegistry::ORDER,
                'origin_id' => $order->id,
                'amount' => $amount,
                'description' => "Abono factura {$order->serie}",
                'user_id' => Auth::id(),
            ]);

            $order->increment('paid', $amount);

            return $this->ledgerService->registerPayment($order, $amount, $date);
        });
    }
}
