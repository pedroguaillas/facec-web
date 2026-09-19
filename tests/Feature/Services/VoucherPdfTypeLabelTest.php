<?php

use App\StaticClasses\VoucherTypes;

function renderInformationPartial(string $voucherTypeSlug, bool $isElectronic = true): string
{
    $movement = (object) ['serie' => '001-001-000000001', 'authorization' => 'auth-123', 'autorized' => null, 'xml' => null];
    $company = (object) ['ruc' => '1234567890001'];

    return view('vouchers.theme.information', compact('movement', 'company', 'voucherTypeSlug', 'isElectronic'))->render();
}

test('el PDF de factura muestra FACTURA, no OTROS', function () {
    $html = renderInformationPartial(VoucherTypes::VENTA_FACTURA);

    expect($html)->toContain('FACTURA')->not->toContain('OTROS');
});

test('el PDF de nota de credito muestra NOTA DE CRÉDITO, no OTROS', function () {
    $html = renderInformationPartial(VoucherTypes::VENTA_NOTA_CREDITO);

    expect($html)->toContain('NOTA DE CRÉDITO')->not->toContain('OTROS');
});

test('el PDF de liquidacion de compra muestra su etiqueta, no OTROS', function () {
    $html = renderInformationPartial(VoucherTypes::COMPRA_LIQUIDACION_COMPRA);

    expect($html)->toContain('LIQUIDACIÓN EN COMPRA')->not->toContain('OTROS');
});

test('el PDF de retencion muestra COMPROBANTE DE RETENCIÓN, no OTROS', function () {
    $html = renderInformationPartial(VoucherTypes::RETENCION);

    expect($html)->toContain('COMPROBANTE DE RETENCIÓN')->not->toContain('OTROS');
});

test('el PDF de guia de remision muestra GUIA DE REMISIÓN, no OTROS', function () {
    $html = renderInformationPartial(VoucherTypes::GUIA_REMISION);

    expect($html)->toContain('GUIA DE REMISIÓN')->not->toContain('OTROS');
});

test('un slug desconocido cae en OTROS (comportamiento default, no un crash)', function () {
    $html = renderInformationPartial('slug_inexistente');

    expect($html)->toContain('OTROS');
});

test('un comprobante no electronico no muestra los campos de autorizacion SRI', function () {
    $html = renderInformationPartial(VoucherTypes::VENTA_PROFORMA, isElectronic: false);

    expect($html)->not->toContain('NÚMERO DE AUTORIZACIÓN')
        ->not->toContain('CLAVE DE ACCESO')
        ->toContain('sin autorización SRI');
});

test('un comprobante electronico si muestra los campos de autorizacion SRI', function () {
    $html = renderInformationPartial(VoucherTypes::VENTA_FACTURA, isElectronic: true);

    expect($html)->toContain('NÚMERO DE AUTORIZACIÓN')
        ->toContain('CLAVE DE ACCESO');
});
