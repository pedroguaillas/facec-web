<?php

use App\Services\Order\OrderTotalsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

function ivaPercentages(): Collection
{
    return collect([0 => 0, 5 => 5, 8 => 8, 2 => 12, 4 => 15]);
}

test('la tarifa 12% (codigo IVA 2) esta descontinuada: base12/iva quedan en 0 y el item no aporta al sub_total', function () {
    $result = (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 2, 'price' => 10, 'discount' => 0, 'iva' => 2],
        ],
        discount: 0,
        percentages: ivaPercentages(),
    );

    expect($result['base12'])->toBe(0.0)
        ->and($result['iva'])->toBe(0.0)
        ->and($result['sub_total'])->toBe(0.0)
        ->and($result['total'])->toBe(0.0);
});

test('un item con codigo 12% mezclado con tarifas activas se ignora sin contaminar el resto', function () {
    $result = (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 2],
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4],
        ],
        discount: 0,
        percentages: ivaPercentages(),
    );

    expect($result['base12'])->toBe(0.0)
        ->and($result['iva'])->toBe(0.0)
        ->and($result['base15'])->toBe(100.0)
        ->and($result['iva15'])->toBe(15.0)
        ->and($result['sub_total'])->toBe(100.0)
        ->and($result['total'])->toBe(115.0);
});

test('agrupa un item no objeto de IVA (code 6)', function () {
    $result = (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 50, 'discount' => 0, 'iva' => 6],
        ],
        discount: 0,
        percentages: ivaPercentages(),
    );

    expect($result['no_iva'])->toBe(50.0)
        ->and($result['iva'])->toBe(0.0)
        ->and($result['sub_total'])->toBe(50.0)
        ->and($result['total'])->toBe(50.0);
});

test('mezcla varias tarifas activas (0%, 5%, 8%, 15%) y descuento global', function () {
    $result = (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 0],
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 5],
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 8],
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4],
        ],
        discount: 10,
        percentages: ivaPercentages(),
    );

    expect($result['base0'])->toBe(100.0)
        ->and($result['base5'])->toBe(100.0)
        ->and($result['base8'])->toBe(100.0)
        ->and($result['base15'])->toBe(100.0)
        ->and($result['iva5'])->toBe(5.0)
        ->and($result['iva8'])->toBe(8.0)
        ->and($result['iva15'])->toBe(15.0)
        ->and($result['sub_total'])->toBe(400.0)
        ->and($result['total'])->toBe(418.0);
});

test('suma el ICE a la base imponible y al total', function () {
    $result = (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'ice' => 20, 'iva' => 4],
        ],
        discount: 0,
        percentages: ivaPercentages(),
    );

    expect($result['base15'])->toBe(120.0)
        ->and($result['iva15'])->toBe(18.0)
        ->and($result['ice'])->toBe(20.0)
        ->and($result['sub_total'])->toBe(120.0)
        ->and($result['total'])->toBe(158.0);
});

test('no loggea cuando la diferencia esta dentro de la tolerancia', function () {
    Log::spy();

    (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4],
        ],
        discount: 0,
        submitted: ['sub_total' => 100.0, 'total' => 115.01],
        percentages: ivaPercentages(),
    );

    Log::shouldNotHaveReceived('warning');
});

test('loggea cuando la diferencia supera la tolerancia', function () {
    Log::spy();

    (new OrderTotalsCalculator)->calculate(
        products: [
            ['quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4],
        ],
        discount: 0,
        submitted: ['sub_total' => 100.0, 'total' => 69.0],
        orderId: 42,
        percentages: ivaPercentages(),
    );

    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
        return str_contains($message, 'Order totals mismatch')
            && $context['order_id'] === 42
            && $context['field'] === 'total';
    });
});
