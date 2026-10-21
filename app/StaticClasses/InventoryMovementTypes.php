<?php

namespace App\StaticClasses;

/**
 * Tipos de movimiento del kardex (`inventories`). Los nombres en español
 * coinciden con los ya usados como strings sueltos antes de esta clase.
 * El signo lo da `direction` (IN/OUT), no el tipo: `REVERSAL` puede ser
 * cualquiera de los dos (es el opuesto del movimiento que revierte).
 */
class InventoryMovementTypes
{
    const IN = 'in';

    const OUT = 'out';

    const SALE = 'Venta';

    const SALE_RETURN = 'Devolución en venta';

    const PURCHASE = 'Compra';

    const OPENING_BALANCE = 'Inventario inicial';

    const ADJUSTMENT_IN = 'Ajuste ingreso';

    const ADJUSTMENT_OUT = 'Ajuste salida';

    const TRANSFER_IN = 'Transferencia ingreso';

    const TRANSFER_OUT = 'Transferencia salida';

    const REVERSAL = 'Reversa';

    /** Tipos que suman al saldo. */
    const INCREASE_TYPES = [
        self::PURCHASE,
        self::SALE_RETURN,
        self::OPENING_BALANCE,
        self::ADJUSTMENT_IN,
        self::TRANSFER_IN,
    ];

    /** Tipos que restan del saldo. */
    const DECREASE_TYPES = [
        self::SALE,
        self::ADJUSTMENT_OUT,
        self::TRANSFER_OUT,
    ];

    /**
     * Movimiento que genera cada tipo de comprobante. Los que no están
     * (proforma, nota de débito, retención, guía) no mueven stock.
     *
     * @var array<string, string>
     */
    const VOUCHER_MOVEMENTS = [
        VoucherTypes::VENTA_FACTURA => self::SALE,
        VoucherTypes::VENTA_NOTA_VENTA => self::SALE,
        VoucherTypes::VENTA_NOTA_CREDITO => self::SALE_RETURN,
        VoucherTypes::COMPRA_FACTURA => self::PURCHASE,
        VoucherTypes::COMPRA_NOTA_VENTA => self::PURCHASE,
        VoucherTypes::COMPRA_LIQUIDACION_COMPRA => self::PURCHASE,
    ];

    public static function forVoucher(string $voucherSlug): ?string
    {
        return self::VOUCHER_MOVEMENTS[$voucherSlug] ?? null;
    }

    public static function directionOf(string $type): string
    {
        return in_array($type, self::INCREASE_TYPES, true) ? self::IN : self::OUT;
    }
}
