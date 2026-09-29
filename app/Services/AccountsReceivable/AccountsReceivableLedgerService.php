<?php

namespace App\Services\AccountsReceivable;

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\AccountsReceivable\CustomerAdvance;
use App\Models\Customer;
use App\Models\Order\Order;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        if ($order->payment_type === PaymentTypes::ANTICIPO) {
            $this->applyAdvance($order);

            return;
        }

        $pendiente = (float) $order->total - (float) $order->paid;

        if (! ($pendiente > 0 && $order->payment_type === PaymentTypes::CREDITO)) {
            return;
        }

        $this->createMovement(
            $order->branch_id,
            $order->customer_id,
            $order->id,
            AccountsReceivableMovementTypes::FACTURA,
            debe: $pendiente,
            haber: 0,
            description: "Factura {$order->serie} — {$pendiente}",
            date: $order->date,
        );
    }

    /**
     * Registra un anticipo recibido de un cliente (sin factura detrás).
     * Invocado por AccountsReceivableAdvanceService, ya dentro de su transacción.
     */
    public function registerAdvance(Customer $customer, float $amount, ?string $date = null): AccountReceivableMovement
    {
        return $this->createMovement(
            $customer->branch_id,
            $customer->id,
            null,
            AccountsReceivableMovementTypes::ANTICIPO,
            debe: 0,
            haber: $amount,
            description: "Anticipo recibido — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    /**
     * Aplica los anticipos disponibles del cliente (FIFO) contra el total de
     * la factura. Si no alcanzan, el resto queda como crédito normal — la
     * factura carga el TOTAL y la aplicación de anticipo abona lo consumido,
     * dejando pendiente solo la diferencia.
     */
    private function applyAdvance(Order $order): void
    {
        $advances = CustomerAdvance::where('customer_id', $order->customer_id)
            ->whereColumn('applied_amount', '<', 'amount')
            ->lockForUpdate()
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $remaining = (float) $order->total;
        $aplicado = 0.0;

        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $advance->amount - (float) $advance->applied_amount;
            $take = min($available, $remaining);

            $advance->increment('applied_amount', $take);
            $aplicado += $take;
            $remaining -= $take;
        }

        $order->update(['paid' => $aplicado]);

        $this->createMovement(
            $order->branch_id,
            $order->customer_id,
            $order->id,
            AccountsReceivableMovementTypes::FACTURA,
            debe: (float) $order->total,
            haber: 0,
            description: "Factura {$order->serie} — {$order->total}",
            date: $order->date,
        );

        if ($aplicado > 0) {
            // Memo: el cargo de la factura ya neta contra el anticipo (haber) por
            // aritmética normal del saldo — debe=haber acá es a propósito, deja
            // trazabilidad de qué anticipo cubrió qué factura sin mover el saldo
            // una segunda vez.
            $this->createMovement(
                $order->branch_id,
                $order->customer_id,
                $order->id,
                AccountsReceivableMovementTypes::APLICACION_ANTICIPO,
                debe: $aplicado,
                haber: $aplicado,
                description: "Anticipo aplicado a factura {$order->serie} — {$aplicado}",
                date: $order->date,
            );
        }
    }

    /**
     * Registra un abono/pago de un cliente contra una factura específica.
     * Invocado por AccountsReceivablePaymentService, ya dentro de su transacción.
     */
    public function registerPayment(Order $order, float $amount, ?string $date = null): AccountReceivableMovement
    {
        return $this->createMovement(
            $order->branch_id,
            $order->customer_id,
            $order->id,
            AccountsReceivableMovementTypes::PAGO,
            debe: 0,
            haber: $amount,
            description: "Abono a factura {$order->serie} — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    /**
     * Registra el saldo que un cliente ya arrastraba antes de migrar a facec
     * (sin factura real detrás). Rechaza si ya existe uno para ese cliente.
     */
    public function registerOpeningBalance(Customer $customer, float $amount, ?string $date = null): AccountReceivableMovement
    {
        $exists = AccountReceivableMovement::where('customer_id', $customer->id)
            ->where('type', AccountsReceivableMovementTypes::SALDO_INICIAL)
            ->exists();

        if ($exists) {
            throw new RuntimeException('Ya existe un saldo inicial registrado para este cliente.');
        }

        return $this->createMovement(
            $customer->branch_id,
            $customer->id,
            null,
            AccountsReceivableMovementTypes::SALDO_INICIAL,
            debe: $amount,
            haber: 0,
            description: "Saldo inicial — {$amount}",
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
            $original->branch_id,
            $original->customer_id,
            $original->id,
            AccountsReceivableMovementTypes::NOTA_CREDITO,
            debe: 0,
            haber: $monto,
            description: "Nota de crédito {$notaCredito->serie} aplicada a factura {$original->serie}",
            date: $notaCredito->date,
        );
    }

    private function createMovement(
        int $branchId,
        int $customerId,
        ?int $orderId,
        string $type,
        float $debe,
        float $haber,
        string $description,
        $date,
    ): AccountReceivableMovement {
        // Lockea al cliente para serializar escrituras concurrentes de cartera
        // sobre el mismo cliente (no hay columna de saldo que lockear: el saldo
        // se deriva del último movimiento).
        Customer::where('id', $customerId)->lockForUpdate()->first();

        $last = AccountReceivableMovement::where('customer_id', $customerId)
            ->orderByDesc('id')
            ->first();

        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $newBalance = $previousBalance + $debe - $haber;

        return AccountReceivableMovement::create([
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'order_id' => $orderId,
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
