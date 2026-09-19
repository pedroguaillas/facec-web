<?php

use App\Models\Branch;
use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Services\VoucherSequenceService;
use App\StaticClasses\VoucherTypes;

test('reserveNext arranca en 1 y crea la fila de secuencia la primera vez', function () {
    $point = EmisionPoint::factory()->create();

    $number = app(VoucherSequenceService::class)->reserveNext($point, VoucherTypes::VENTA_FACTURA);

    expect($number)->toBe(1);
    $this->assertDatabaseHas('emision_point_sequences', [
        'emision_point_id' => $point->id,
        'next_number' => 2,
    ]);
});

test('reserveNext incrementa en llamadas sucesivas para el mismo tipo', function () {
    $point = EmisionPoint::factory()->create();
    $service = app(VoucherSequenceService::class);

    $first = $service->reserveNext($point, VoucherTypes::VENTA_FACTURA);
    $second = $service->reserveNext($point, VoucherTypes::VENTA_FACTURA);
    $third = $service->reserveNext($point, VoucherTypes::VENTA_FACTURA);

    expect([$first, $second, $third])->toBe([1, 2, 3]);
});

test('cada tipo de comprobante mantiene su propia secuencia independiente', function () {
    $point = EmisionPoint::factory()->create();
    $service = app(VoucherSequenceService::class);

    $service->reserveNext($point, VoucherTypes::VENTA_FACTURA);
    $service->reserveNext($point, VoucherTypes::VENTA_FACTURA);
    $notaCreditoNumber = $service->reserveNext($point, VoucherTypes::VENTA_NOTA_CREDITO);

    expect($notaCreditoNumber)->toBe(1);
    expect(EmisionPointSequence::count())->toBe(2);
});

test('generateSerie arma estab-ptoEmi-secuencial de 17 caracteres a partir del propio punto de emisión', function () {
    $branch = Branch::factory()->create(['store' => 1]);
    $point = EmisionPoint::factory()->create(['branch_id' => $branch->id, 'point' => 1]);

    $serie = app(VoucherSequenceService::class)->generateSerie($point, VoucherTypes::COMPRA_LIQUIDACION_COMPRA);

    expect($serie)->toBe('001-001-000000001')
        ->and(strlen($serie))->toBe(17);
});

test('generateSerie usa el store/point reales del punto, no un valor enviado por el cliente', function () {
    $branch = Branch::factory()->create(['store' => 5]);
    $point = EmisionPoint::factory()->create(['branch_id' => $branch->id, 'point' => 12]);

    $serie = app(VoucherSequenceService::class)->generateSerie($point, VoucherTypes::VENTA_FACTURA);

    expect($serie)->toBe('005-012-000000001');
});
