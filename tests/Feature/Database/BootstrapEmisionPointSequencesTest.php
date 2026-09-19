<?php

use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;

function runBootstrapMigration(): void
{
    $migration = require database_path('migrations/2026_09_08_095000_bootstrap_emision_point_sequences_from_emision_points.php');
    $migration->up();
}

test('bootstrap copia los contadores viejos de emision_points a emision_point_sequences', function () {
    $point = EmisionPoint::factory()->create([
        'invoice' => 47,
        'creditnote' => 3,
        'retention' => 2,
        'referralguide' => 6,
        'settlementonpurchase' => 9,
    ]);

    expect(EmisionPointSequence::count())->toBe(0);

    runBootstrapMigration();

    $expected = [
        VoucherTypes::VENTA_FACTURA => 47,
        VoucherTypes::VENTA_NOTA_CREDITO => 3,
        VoucherTypes::RETENCION => 2,
        VoucherTypes::GUIA_REMISION => 6,
        VoucherTypes::COMPRA_LIQUIDACION_COMPRA => 9,
    ];

    foreach ($expected as $slug => $nextNumber) {
        $voucherTypeId = VoucherType::where('slug', $slug)->value('id');

        $sequence = EmisionPointSequence::where('emision_point_id', $point->id)
            ->where('voucher_type_id', $voucherTypeId)
            ->first();

        expect($sequence)->not->toBeNull("falta la secuencia para {$slug}")
            ->and($sequence->next_number)->toBe($nextNumber);
    }

    expect(EmisionPointSequence::count())->toBe(5);
});

test('bootstrap no pisa una secuencia que ya se emitió tras el deploy', function () {
    $point = EmisionPoint::factory()->create(['invoice' => 47]);
    $invoiceTypeId = VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id');

    // Simula que ya se creó una factura nueva ANTES de correr el bootstrap
    // (la fila ya avanzó a 48 por el flujo normal de VoucherSequenceService).
    EmisionPointSequence::create([
        'emision_point_id' => $point->id,
        'voucher_type_id' => $invoiceTypeId,
        'next_number' => 48,
    ]);

    runBootstrapMigration();

    $sequence = EmisionPointSequence::where('emision_point_id', $point->id)
        ->where('voucher_type_id', $invoiceTypeId)
        ->first();

    expect($sequence->next_number)->toBe(48);
});

test('bootstrap corriendo dos veces no duplica filas', function () {
    EmisionPoint::factory()->create(['invoice' => 10]);

    runBootstrapMigration();
    runBootstrapMigration();

    expect(EmisionPointSequence::count())->toBe(5);
});
