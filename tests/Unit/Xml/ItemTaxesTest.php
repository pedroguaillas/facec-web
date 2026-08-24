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

        public function group(iterable $items): array
        {
            return $this->groupTaxes($items);
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

test('groupTaxes acumula la base sin redondear por linea', function () {
    $items = [
        (object) ['quantity' => 1, 'price' => 0.434783, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 4, 'percentage' => 15, 'codice' => null],
        (object) ['quantity' => 1, 'price' => 0.434783, 'valice' => 0.0, 'discount' => 0.0, 'iva' => 4, 'percentage' => 15, 'codice' => null],
    ];

    $taxes = taxRenderer()->group($items);

    expect($taxes)->toHaveCount(1)
        ->and($taxes[0]->base)->toBe(0.869566);
});
