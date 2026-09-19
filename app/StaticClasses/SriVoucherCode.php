<?php

namespace App\StaticClasses;

/**
 * Códigos oficiales del catálogo "tipoComprobante" (codDoc) del SRI.
 * Fijos por el SRI, no deben cambiar salvo actualización de su normativa.
 */
class SriVoucherCode
{
    const FACTURA = '01';

    const LIQUIDACION_COMPRA = '03';

    const NOTA_CREDITO = '04';

    const NOTA_DEBITO = '05';

    const GUIA_REMISION = '06';

    const RETENCION = '07';
}
