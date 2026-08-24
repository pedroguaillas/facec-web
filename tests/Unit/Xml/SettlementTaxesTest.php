<?php

use App\Models\Company;
use App\Xml\SettlementOnPurchaseBuilder;
use Tests\TestCase;

uses(TestCase::class);

function settlementBuilder(array $items): SettlementOnPurchaseBuilder
{
    return new SettlementOnPurchaseBuilder(new Company, null, collect($items));
}

function invokeGroupSettlementTaxes(SettlementOnPurchaseBuilder $builder): array
{
    $method = new ReflectionMethod($builder, 'groupSettlementTaxes');

    return $method->invoke($builder);
}

test('groupSettlementTaxes acumula la base sin redondear por linea', function () {
    $builder = settlementBuilder([
        (object) ['quantity' => 1, 'price' => 0.434783, 'iva' => 4],
        (object) ['quantity' => 1, 'price' => 0.434783, 'iva' => 4],
    ]);

    $taxes = invokeGroupSettlementTaxes($builder);

    expect($taxes)->toHaveCount(1)
        ->and($taxes[0]->base)->toBe(0.869566)
        ->and($taxes[0]->percentage)->toBe(15);
});

test('el valor del IVA agrupado se calcula sobre la base sin redondear (0.434783 -> 0.07)', function () {
    $builder = settlementBuilder([
        (object) ['quantity' => 1, 'price' => 0.434783, 'iva' => 4],
    ]);

    $tax = invokeGroupSettlementTaxes($builder)[0];
    $valor = round($tax->base * $tax->percentage / 100, 2);

    expect($valor)->toBe(0.07);
});

test('un precio redondo sigue dando el mismo IVA agrupado', function () {
    $builder = settlementBuilder([
        (object) ['quantity' => 1, 'price' => 10.0, 'iva' => 4],
    ]);

    $tax = invokeGroupSettlementTaxes($builder)[0];

    expect($tax->base)->toBe(10.0)
        ->and(round($tax->base * $tax->percentage / 100, 2))->toBe(1.5);
});
