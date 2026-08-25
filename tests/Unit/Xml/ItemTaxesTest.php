<?php

use App\Xml\Concerns\HasItemTaxes;
use Tests\TestCase;

uses(TestCase::class);

function taxRenderer(): object
{
    return new class
    {
        use HasItemTaxes;

        public function render($detail, float $subTotal): string
        {
            return $this->itemImpuestos($detail, $subTotal);
        }

        public function group(iterable $items, $order): array
        {
            return $this->groupTaxes($items, $order);
        }
    };
}

function taxDetail(array $overrides = []): object
{
    return (object) array_merge([
        'iva' => 4,
        'percentage' => 15,
        'codice' => null,
        'valice' => 0.0,
        'discount' => 0.0,
    ], $overrides);
}

test('el IVA del item se calcula sobre la base sin redondear (bug 0.434783 -> 0.07)', function () {
    $subTotal = 1 * 0.434783;
    $xml = taxRenderer()->render(taxDetail(), $subTotal);

    expect($xml)->toContain('<baseImponible>0.43</baseImponible>')
        ->and($xml)->toContain('<valor>0.07</valor>');
});

test('un precio redondo sigue dando el mismo resultado (no rompe casos correctos)', function () {
    $xml = taxRenderer()->render(taxDetail(), 10.0);

    expect($xml)->toContain('<baseImponible>10</baseImponible>')
        ->and($xml)->toContain('<valor>1.5</valor>');
});

test('groupTaxes no recalcula: toma base y valor ya persistidos en la orden', function () {
    $items = [
        (object) ['quantity' => 1, 'price' => 0.434783, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 4, 'percentage' => 15, 'codice' => null],
        (object) ['quantity' => 1, 'price' => 0.434783, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 4, 'percentage' => 15, 'codice' => null],
    ];
    $order = (object) ['base15' => 0.87, 'iva15' => 0.13];

    $taxes = taxRenderer()->group($items, $order);

    expect($taxes)->toHaveCount(1)
        ->and($taxes[0]->base)->toBe(0.87)
        ->and($taxes[0]->valor)->toBe(0.13);
});

test('groupTaxes agrupa distintos codigos de IVA leyendo cada uno de su columna de orden', function () {
    $items = [
        (object) ['quantity' => 1, 'price' => 100.0, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 4, 'percentage' => 15, 'codice' => null],
        (object) ['quantity' => 1, 'price' => 50.0, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 5, 'percentage' => 5, 'codice' => null],
    ];
    $order = (object) ['base15' => 100.0, 'iva15' => 15.0, 'base5' => 50.0, 'iva5' => 2.5];

    $taxes = taxRenderer()->group($items, $order);

    expect($taxes)->toHaveCount(2)
        ->and($taxes[0]->base)->toBe(100.0)
        ->and($taxes[0]->valor)->toBe(15.0)
        ->and($taxes[1]->base)->toBe(50.0)
        ->and($taxes[1]->valor)->toBe(2.5);
});
