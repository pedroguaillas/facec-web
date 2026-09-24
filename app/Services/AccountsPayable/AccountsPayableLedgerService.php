<?php

namespace App\Services\AccountsPayable;

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\StaticClasses\AccountsPayableMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

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

        $pendiente = (float) $shop->total - (float) $shop->paid;

        if (! ($pendiente > 0 && $shop->payment_type === PaymentTypes::CREDITO)) {
            return;
        }

        $this->createMovement(
            $shop,
            AccountsPayableMovementTypes::FACTURA,
            debe: 0,
            haber: $pendiente,
            description: "Factura proveedor {$shop->serie} — {$pendiente}",
        );

        $retencion = (float) $shop->shopretentionitems()->sum('value');

        if ($retencion > 0) {
            $this->createMovement(
                $shop,
                AccountsPayableMovementTypes::RETENCION,
                debe: $retencion,
                haber: 0,
                description: "Retención {$shop->serie_retencion} aplicada a factura {$shop->serie}",
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
            $shop,
            AccountsPayableMovementTypes::PAGO,
            debe: $amount,
            haber: 0,
            description: "Abono a factura {$shop->serie} — {$amount}",
            date: $date ?? now()->format('Y-m-d'),
        );
    }

    private function createMovement(
        Shop $shop,
        string $type,
        float $debe,
        float $haber,
        string $description,
        ?string $date = null,
    ): AccountPayableMovement {
        // Lockea al proveedor para serializar escrituras concurrentes de
        // cartera sobre el mismo proveedor (el saldo se deriva del último
        // movimiento, no hay columna de saldo que lockear).
        Provider::where('id', $shop->provider_id)->lockForUpdate()->first();

        $last = AccountPayableMovement::where('provider_id', $shop->provider_id)
            ->orderByDesc('id')
            ->first();

        $previousBalance = $last ? (float) $last->new_balance : 0.0;
        $newBalance = $previousBalance + $haber - $debe;

        return AccountPayableMovement::create([
            'branch_id' => $shop->branch_id,
            'provider_id' => $shop->provider_id,
            'shop_id' => $shop->id,
            'type' => $type,
            'debe' => $debe,
            'haber' => $haber,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'description' => $description,
            'date' => $date ?? $shop->date,
        ]);
    }
}
