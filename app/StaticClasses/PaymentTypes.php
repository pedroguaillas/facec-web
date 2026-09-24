<?php

namespace App\StaticClasses;

/**
 * Clasificación comercial del pago de una venta/compra — independiente del
 * `pay_method` (instrumento SRI, catálogo Tabla 24). Reemplaza la inferencia
 * anterior basada en `expiration_days`/`paid`.
 */
class PaymentTypes
{
    const CONTADO = 'CONTADO';

    const CREDITO = 'CREDITO';

    const ANTICIPO = 'ANTICIPO';
}
