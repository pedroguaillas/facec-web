<?php

use Illuminate\Support\Collection;

function renderAdiinfopayment(Collection $orderaditionals, ?string $payMethod = null): string
{
    $movement = (object) ['total' => 57.50];
    $data = compact('movement', 'orderaditionals');

    if ($payMethod !== null) {
        $data['payMethod'] = $payMethod;
    }

    return view('vouchers.theme.adiinfopayment', $data)->render();
}

test('muestra INFORMACIÓN ADICIONAL cuando hay orderaditionals, sin necesitar payMethod (nota de crédito)', function () {
    $aditional = (object) ['name' => 'Guía de Remisión', 'description' => '001-001-000000042'];

    $html = renderAdiinfopayment(collect([$aditional]));

    expect($html)->toContain('INFORMACIÓN ADICIONAL')
        ->toContain('Guía de Remisión')
        ->toContain('001-001-000000042')
        ->not->toContain('Forma de pago');
});

test('no muestra la tabla de INFORMACIÓN ADICIONAL cuando no hay orderaditionals', function () {
    $html = renderAdiinfopayment(collect());

    expect($html)->not->toContain('INFORMACIÓN ADICIONAL');
});

test('muestra Forma de pago solo cuando se pasa payMethod (factura/nota de venta)', function () {
    $html = renderAdiinfopayment(collect(), 'EFECTIVO');

    expect($html)->toContain('Forma de pago')
        ->toContain('EFECTIVO');
});
