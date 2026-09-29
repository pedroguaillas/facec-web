<?php

namespace App\StaticClasses;

/**
 * Tipos de movimiento de la cartera de cuentas por cobrar (clientes).
 */
class AccountsReceivableMovementTypes
{
    const FACTURA = 'FACTURA';

    const NOTA_CREDITO = 'NOTA_CREDITO';

    const PAGO = 'PAGO';

    const SALDO_INICIAL = 'SALDO_INICIAL';

    const ANTICIPO = 'ANTICIPO';

    const APLICACION_ANTICIPO = 'APLICACION_ANTICIPO';
}
