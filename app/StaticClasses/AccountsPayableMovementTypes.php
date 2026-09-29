<?php

namespace App\StaticClasses;

/**
 * Tipos de movimiento de la cartera de cuentas por pagar (proveedores).
 */
class AccountsPayableMovementTypes
{
    const FACTURA = 'FACTURA';

    const RETENCION = 'RETENCION';

    const PAGO = 'PAGO';

    const SALDO_INICIAL = 'SALDO_INICIAL';

    const ANTICIPO = 'ANTICIPO';

    const APLICACION_ANTICIPO = 'APLICACION_ANTICIPO';
}
