<?php

namespace Database\Seeders;

use App\Models\VoucherType;
use App\StaticClasses\SriVoucherCode;
use App\StaticClasses\VoucherTypes;
use Illuminate\Database\Seeder;

class VoucherTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // Ventas
            [
                'slug' => VoucherTypes::VENTA_FACTURA,
                'module' => 'venta',
                'code_sri' => SriVoucherCode::FACTURA,
                'name' => 'Factura',
                'abbreviation' => 'FAC',
                'requires_electronic' => true,
                'requires_sequence' => true,
            ],
            [
                'slug' => VoucherTypes::VENTA_NOTA_CREDITO,
                'module' => 'venta',
                'code_sri' => SriVoucherCode::NOTA_CREDITO,
                'name' => 'Nota de Crédito',
                'abbreviation' => 'N/C',
                'requires_electronic' => true,
                'requires_sequence' => true,
            ],
            [
                'slug' => VoucherTypes::VENTA_NOTA_VENTA,
                'module' => 'venta',
                'code_sri' => null,
                'name' => 'Nota de Venta',
                'abbreviation' => 'N/V',
                'requires_electronic' => false,
                'requires_sequence' => true,
            ],
            [
                'slug' => VoucherTypes::VENTA_PROFORMA,
                'module' => 'venta',
                'code_sri' => null,
                'name' => 'Proforma',
                'abbreviation' => 'PRO',
                'requires_electronic' => false,
                'requires_sequence' => true,
            ],

            // Compras: factura/nota de venta/nota de débito las EMITE el proveedor
            // (el contribuyente solo las registra, su secuencial no nos pertenece).
            // Solo la liquidación de compra la emite el propio contribuyente.
            [
                'slug' => VoucherTypes::COMPRA_FACTURA,
                'module' => 'compra',
                'code_sri' => SriVoucherCode::FACTURA,
                'name' => 'Factura',
                'abbreviation' => 'FAC',
                'requires_electronic' => false,
                'requires_sequence' => false,
            ],
            [
                'slug' => VoucherTypes::COMPRA_NOTA_VENTA,
                'module' => 'compra',
                'code_sri' => null,
                'name' => 'Nota de Venta',
                'abbreviation' => 'N/V',
                'requires_electronic' => false,
                'requires_sequence' => false,
            ],
            [
                'slug' => VoucherTypes::COMPRA_LIQUIDACION_COMPRA,
                'module' => 'compra',
                'code_sri' => SriVoucherCode::LIQUIDACION_COMPRA,
                'name' => 'Liquidación de Compra',
                'abbreviation' => 'L/C',
                'requires_electronic' => true,
                'requires_sequence' => true,
            ],
            [
                'slug' => VoucherTypes::COMPRA_NOTA_DEBITO,
                'module' => 'compra',
                'code_sri' => SriVoucherCode::NOTA_DEBITO,
                'name' => 'Nota de Débito',
                'abbreviation' => 'N/D',
                'requires_electronic' => false,
                'requires_sequence' => false,
            ],

            // Comunes
            [
                'slug' => VoucherTypes::RETENCION,
                'module' => 'comun',
                'code_sri' => SriVoucherCode::RETENCION,
                'name' => 'Comprobante de Retención',
                'abbreviation' => 'RET',
                'requires_electronic' => true,
                'requires_sequence' => true,
            ],
            [
                'slug' => VoucherTypes::GUIA_REMISION,
                'module' => 'comun',
                'code_sri' => SriVoucherCode::GUIA_REMISION,
                'name' => 'Guía de Remisión',
                'abbreviation' => 'G/R',
                'requires_electronic' => true,
                'requires_sequence' => true,
            ],
        ];

        foreach ($types as $type) {
            VoucherType::updateOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
