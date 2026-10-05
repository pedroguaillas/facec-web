<?php

namespace App\StaticClasses;

/**
 * Tipos de movimiento del kardex (`inventories`). Los nombres en español
 * coinciden con los ya usados como strings sueltos antes de esta clase.
 */
class InventoryMovementTypes
{
    const SALE = 'Venta';

    const PURCHASE = 'Compra';

    const OPENING_BALANCE = 'Inventario inicial';

    /** Tipos que suman al saldo. */
    const INCREASE_TYPES = [
        self::PURCHASE,
        self::OPENING_BALANCE,
    ];

    /** Tipos que restan del saldo. */
    const DECREASE_TYPES = [
        self::SALE,
    ];
}
