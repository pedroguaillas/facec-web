<?php

namespace App\Services\AccountsReceivable;

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Customer;
use App\Models\Order\Order;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Log;

/**
 * Genera los movimientos de cartera (cuentas por cobrar) al registrar una
 * venta a crédito. Se ejecuta dentro de la misma transacción que crea el
 * Order — es independiente del estado de autorización SRI.
 */
class AccountsReceivableLedgerService
{
    /**
     * Slugs de Order que representan una venta real (cargan al cliente).
     * venta_proforma queda fuera a propósito: no es una venta, no genera cartera.
     */
    private const CARGO_SLUGS = [
        VoucherTypes::VENTA_FACTURA,
        VoucherTypes::VENTA_NOTA_VENTA,
    ];

    public function registerFromOrder(Order $order): void
    {
        $slug = $order->voucherType->slug;

        if ($slug === VoucherTypes::VENTA_NOTA_CREDITO) {
            $this->registerNotaCredito($order);

            return;
        }

        if (! in_array($slug, self::CARGO_SLUGS, true)) {
            return;
        }

        $pendiente = (float) $order->total - (float) $order->paid;

        if (! ($pendiente > 0 && $order->payment_type === PaymentTypes::CREDITO)) {
            return;
        }

        $this->createMovement(
            $order,
            AccountsReceivableMovementTypes::FACTURA,
            debe: $pendiente,
            haber: 0,
            description: "Factura {$order->serie} — {$pendiente}",
            date: $order->date,
        );
    }

    /**
     * Registra un abono/pago de un cliente contra una factura específica.
     * Invocado por AccountsReceivablePaymentService, ya dentro de su transacción.
     */
    public function registerPayment(Order $order, float $amount, ?string $date = null): AccountReceivableMovement
    {
        return $this->createMovement(
            $order,
            AccountsReceivableMovementTypes::PAGO,
            debe: 0,
            haber: $amount,
            description: "Abono a factura {$order->serie} — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    private function registerNotaCredito(Order $notaCredito): void
    {
        $original = Order::where('serie', $notaCredito->serie_order)
            ->where('date', $notaCredito->date_order)
            ->first();

        if (! $original) {
            Log::warning('Nota de crédito sin factura original resolvible, no se generó movimiento de cartera.', [
                'nota_credito_id' => $notaCredito->id,
                'serie_order' => $notaCredito->serie_order,
                'date_order' => $notaCredito->date_order,
            ]);

            return;
        }

        $monto = (float) $notaCredito->total;

        if ($monto <= 0) {
            return;
        }

        $this->createMovement(
            $original,
            AccountsReceivableMovementTypes::NOTA_CREDITO,
            debe: 0,
            haber: $monto,
            description: "Nota de crédito {$notaCredito->serie} aplicada a factura {$original->serie}",
            date: $notaCredito->date,
        );
    }

    private function createMovement(
        Order $relatedOrder,
        string $type,
        float $debe,
        float $haber,
        string $description,
        $date,
    ): AccountReceivableMovement {
        // Lockea al cliente para serializar escrituras concurrentes de cartera
        // sobre el mismo cliente (no hay columna de saldo que lockear: el saldo
        // se deriva del último movimiento).
        Customer::where('id', $relatedOrder->customer_id)->lockForUpdate()->first();

        $last = AccountReceivableMovement::where('customer_id', $relatedOrder->customer_id)
            ->orderByDesc('id')
            ->first();

        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $newBalance = $previousBalance + $debe - $haber;

        return AccountReceivableMovement::create([
            'branch_id' => $relatedOrder->branch_id,
            'customer_id' => $relatedOrder->customer_id,
            'order_id' => $relatedOrder->id,
            'type' => $type,
            'debe' => $debe,
            'haber' => $haber,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'description' => $description,
            'date' => $date,
        ]);
    }
}
