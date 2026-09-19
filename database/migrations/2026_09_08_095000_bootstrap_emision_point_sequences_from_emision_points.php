<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `emision_points` traía el secuencial de cada tipo en columnas sueltas
     * (`invoice`, `creditnote`, `settlementonpurchase`, `retention`,
     * `referralguide`). Esas columnas dejaron de escribirse (ver
     * `add_voucher_type_id_to_orders/shops_table` y el refactor a
     * `VoucherSequenceService`), pero en una BD con comprobantes reales
     * siguen teniendo el último valor correcto — sin este bootstrap,
     * `emision_point_sequences` arrancaría vacía y el primer comprobante
     * nuevo reservaría el secuencial 1, chocando con uno ya emitido ante
     * el SRI.
     */
    public function up(): void
    {
        $typeIds = DB::table('voucher_types')->pluck('id', 'slug');

        $columnToSlug = [
            'invoice' => 'venta_factura',
            'creditnote' => 'venta_nota_credito',
            'settlementonpurchase' => 'compra_liquidacion_compra',
            'retention' => 'retencion',
            'referralguide' => 'guia_remision',
        ];

        $points = DB::table('emision_points')->get([
            'id', 'invoice', 'creditnote', 'retention', 'referralguide', 'settlementonpurchase',
        ]);

        $now = now();
        $rows = [];

        foreach ($points as $point) {
            foreach ($columnToSlug as $column => $slug) {
                $voucherTypeId = $typeIds[$slug] ?? null;
                $nextNumber = $point->{$column} ?? null;

                if (! $voucherTypeId || $nextNumber === null) {
                    continue;
                }

                $rows[] = [
                    'emision_point_id' => $point->id,
                    'voucher_type_id' => $voucherTypeId,
                    'next_number' => $nextNumber,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // insertOrIgnore respeta unique(emision_point_id, voucher_type_id):
        // si ya existe una fila (p. ej. se emitió un comprobante nuevo entre
        // que corrió la migración de schema y esta), NO la pisa hacia atrás.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('emision_point_sequences')->insertOrIgnore($chunk);
        }
    }

    /**
     * Reverse the migrations.
     *
     * No-op intencional: para cuando corre este rollback probablemente ya
     * se emitieron comprobantes nuevos sobre estas filas — borrarlas
     * perdería el secuencial real, no lo restaura.
     */
    public function down(): void {}
};
