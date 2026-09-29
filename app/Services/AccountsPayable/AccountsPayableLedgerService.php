<?php

namespace App\Services\AccountsPayable;

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\AccountsPayable\ProviderAdvance;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\StaticClasses\AccountsPayableMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use RuntimeException;

/**
 * Genera los movimientos de cartera (cuentas por pagar) al registrar una
 * compra a crédito. Se ejecuta dentro de la misma transacción que crea el
 * Shop — es independiente del estado de autorización SRI.
 *
 * La retención (cuando aplica) se registra en la misma llamada, encadenada
 * después de la factura, porque `Shop` ya guarda ambas en la misma fila y en
 * la misma transacción (ver `ShopStoreService::createShop`).
 */
class AccountsPayableLedgerService
{
    public function registerFromShop(Shop $shop): void
    {
        $slug = $shop->voucherType->slug;

        if (! in_array($slug, VoucherTypes::COMPRA_SLUGS, true)) {
            return;
        }

        if ($shop->payment_type === PaymentTypes::ANTICIPO) {
            $this->applyAdvance($shop);
            $this->registerRetencion($shop);

            return;
        }

        $pendiente = (float) $shop->total - (float) $shop->paid;

        if (! ($pendiente > 0 && $shop->payment_type === PaymentTypes::CREDITO)) {
            return;
        }

        $this->createMovement(
            $shop->branch_id,
            $shop->provider_id,
            $shop->id,
            AccountsPayableMovementTypes::FACTURA,
            debe: 0,
            haber: $pendiente,
            description: "Factura proveedor {$shop->serie} — {$pendiente}",
            date: $shop->date,
        );

        $this->registerRetencion($shop);
    }

    /**
     * Registra un anticipo pagado a un proveedor (sin compra detrás).
     * Invocado por AccountsPayableAdvanceService, ya dentro de su transacción.
     */
    public function registerAdvance(Provider $provider, float $amount, ?string $date = null): AccountPayableMovement
    {
        return $this->createMovement(
            $provider->branch_id,
            $provider->id,
            null,
            AccountsPayableMovementTypes::ANTICIPO,
            debe: $amount,
            haber: 0,
            description: "Anticipo pagado — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    /**
     * Aplica los anticipos disponibles del proveedor (FIFO) contra el total
     * de la compra. Si no alcanzan, el resto queda como crédito normal — la
     * compra carga el TOTAL y la aplicación de anticipo abona lo consumido,
     * dejando pendiente solo la diferencia.
     */
    private function applyAdvance(Shop $shop): void
    {
        $advances = ProviderAdvance::where('provider_id', $shop->provider_id)
            ->whereColumn('applied_amount', '<', 'amount')
            ->lockForUpdate()
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $remaining = (float) $shop->total;
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

        $shop->update(['paid' => $aplicado]);

        $this->createMovement(
            $shop->branch_id,
            $shop->provider_id,
            $shop->id,
            AccountsPayableMovementTypes::FACTURA,
            debe: 0,
            haber: (float) $shop->total,
            description: "Factura proveedor {$shop->serie} — {$shop->total}",
            date: $shop->date,
        );

        if ($aplicado > 0) {
            // Memo: el cargo de la factura ya neta contra el anticipo (debe) por
            // aritmética normal del saldo — debe=haber acá es a propósito, deja
            // trazabilidad de qué anticipo cubrió qué compra sin mover el saldo
            // una segunda vez.
            $this->createMovement(
                $shop->branch_id,
                $shop->provider_id,
                $shop->id,
                AccountsPayableMovementTypes::APLICACION_ANTICIPO,
                debe: $aplicado,
                haber: $aplicado,
                description: "Anticipo aplicado a factura {$shop->serie} — {$aplicado}",
                date: $shop->date,
            );
        }
    }

    private function registerRetencion(Shop $shop): void
    {
        $retencion = (float) $shop->shopretentionitems()->sum('value');

        if ($retencion > 0) {
            $this->createMovement(
                $shop->branch_id,
                $shop->provider_id,
                $shop->id,
                AccountsPayableMovementTypes::RETENCION,
                debe: $retencion,
                haber: 0,
                description: "Retención {$shop->serie_retencion} aplicada a factura {$shop->serie}",
                date: $shop->date,
            );
        }
    }

    /**
     * Registra un abono/pago de la empresa a un proveedor contra una compra
     * específica. Invocado por AccountsPayablePaymentService, ya dentro de su
     * transacción.
     */
    public function registerPayment(Shop $shop, float $amount, ?string $date = null): AccountPayableMovement
    {
        return $this->createMovement(
            $shop->branch_id,
            $shop->provider_id,
            $shop->id,
            AccountsPayableMovementTypes::PAGO,
            debe: $amount,
            haber: 0,
            description: "Abono a factura {$shop->serie} — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    /**
     * Registra el saldo que ya se le debía a un proveedor antes de migrar a
     * facec (sin compra real detrás). Rechaza si ya existe uno para ese proveedor.
     */
    public function registerOpeningBalance(Provider $provider, float $amount, ?string $date = null): AccountPayableMovement
    {
        $exists = AccountPayableMovement::where('provider_id', $provider->id)
            ->where('type', AccountsPayableMovementTypes::SALDO_INICIAL)
            ->exists();

        if ($exists) {
            throw new RuntimeException('Ya existe un saldo inicial registrado para este proveedor.');
        }

        return $this->createMovement(
            $provider->branch_id,
            $provider->id,
            null,
            AccountsPayableMovementTypes::SALDO_INICIAL,
            debe: 0,
            haber: $amount,
            description: "Saldo inicial — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    private function createMovement(
        int $branchId,
        int $providerId,
        ?int $shopId,
        string $type,
        float $debe,
        float $haber,
        string $description,
        string $date,
    ): AccountPayableMovement {
        // Lockea al proveedor para serializar escrituras concurrentes de
        // cartera sobre el mismo proveedor (el saldo se deriva del último
        // movimiento, no hay columna de saldo que lockear).
        Provider::where('id', $providerId)->lockForUpdate()->first();

        $last = AccountPayableMovement::where('provider_id', $providerId)
            ->orderByDesc('id')
            ->first();

        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $newBalance = $previousBalance + $haber - $debe;

        return AccountPayableMovement::create([
            'branch_id' => $branchId,
            'provider_id' => $providerId,
            'shop_id' => $shopId,
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
