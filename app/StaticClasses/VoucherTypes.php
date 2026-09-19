<?php

namespace App\StaticClasses;

/**
 * Slugs del catálogo `voucher_types`. Úsalos en vez de strings sueltos
 * para referenciar un tipo de comprobante (ver seeder `VoucherTypeSeeder`).
 */
class VoucherTypes
{
    // Ventas
    const VENTA_FACTURA = 'venta_factura';

    const VENTA_NOTA_CREDITO = 'venta_nota_credito';

    const VENTA_NOTA_VENTA = 'venta_nota_venta';

    const VENTA_PROFORMA = 'venta_proforma';

    // Compras
    const COMPRA_FACTURA = 'compra_factura';

    const COMPRA_NOTA_VENTA = 'compra_nota_venta';

    const COMPRA_LIQUIDACION_COMPRA = 'compra_liquidacion_compra';

    const COMPRA_NOTA_DEBITO = 'compra_nota_debito';

    // Comunes
    const RETENCION = 'retencion';

    const GUIA_REMISION = 'guia_remision';

    /** Slugs válidos para el módulo de Ventas (Order.voucher_type_id). */
    const VENTA_SLUGS = [
        self::VENTA_FACTURA,
        self::VENTA_NOTA_CREDITO,
        self::VENTA_NOTA_VENTA,
        self::VENTA_PROFORMA,
    ];

    /** Slugs válidos para el módulo de Compras (Shop.voucher_type_id). */
    const COMPRA_SLUGS = [
        self::COMPRA_FACTURA,
        self::COMPRA_NOTA_VENTA,
        self::COMPRA_LIQUIDACION_COMPRA,
        self::COMPRA_NOTA_DEBITO,
    ];
}
