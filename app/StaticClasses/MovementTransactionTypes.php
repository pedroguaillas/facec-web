<?php

namespace App\StaticClasses;

class MovementTransactionTypes
{
    /** Caja → banco. */
    const DEPOSIT = 'DEPOSITO';

    /** Banco → caja. */
    const WITHDRAWAL = 'RETIRO';

    /** Banco → banco. */
    const TRANSFER = 'TRANSFERENCIA';

    /** Caja general → caja chica/otro. */
    const CASH_REGISTER_OPENING = 'APERTURA_CAJA';

    /** Caja chica/otro → caja general. */
    const CASH_REGISTER_CLOSING = 'CIERRE_CAJA';

    const OTHER = 'OTRO';

    /** Venta de contado (Order): el cobro entra a la cuenta/caja seleccionada. */
    const SALE_COLLECTION = 'COBRO_VENTA';

    /** Compra de contado (Shop): el pago sale de la cuenta/caja seleccionada. */
    const PURCHASE_PAYMENT = 'PAGO_COMPRA';

    /** Saldo con el que arranca una cuenta bancaria al migrar a facec. */
    const OPENING_BALANCE = 'SALDO_INICIAL';

    /** Anticipo recibido de un cliente, antes de que exista la factura. */
    const CUSTOMER_ADVANCE = 'ANTICIPO_CLIENTE';

    /** Anticipo pagado a un proveedor, antes de que exista la compra. */
    const PROVIDER_ADVANCE = 'ANTICIPO_PROVEEDOR';

    const ALL = [
        self::DEPOSIT,
        self::WITHDRAWAL,
        self::TRANSFER,
        self::CASH_REGISTER_OPENING,
        self::CASH_REGISTER_CLOSING,
        self::OTHER,
        self::SALE_COLLECTION,
        self::PURCHASE_PAYMENT,
        self::OPENING_BALANCE,
        self::CUSTOMER_ADVANCE,
        self::PROVIDER_ADVANCE,
    ];
}
