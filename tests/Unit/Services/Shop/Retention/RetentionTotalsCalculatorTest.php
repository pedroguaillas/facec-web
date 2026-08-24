<?php

use App\Services\Shop\Retention\RetentionTotalsCalculator;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('recalculates value from base and porcentage on a single line', function () {
    $result = (new RetentionTotalsCalculator)->recalculate([
        ['code' => '1', 'tax_code' => '312', 'base' => 100.0, 'porcentage' => 1.0],
    ]);

    expect($result[0]['value'])->toBe(1.0)
        ->and($result[0]['code'])->toBe('1')
        ->and($result[0]['tax_code'])->toBe('312')
        ->and($result[0]['base'])->toBe(100.0)
        ->and($result[0]['porcentage'])->toBe(1.0);
});

it('recalculates value across multiple lines', function () {
    $result = (new RetentionTotalsCalculator)->recalculate([
        ['code' => '1', 'tax_code' => '312', 'base' => 100.0, 'porcentage' => 1.0],
        ['code' => '2', 'tax_code' => '322', 'base' => 250.0, 'porcentage' => 2.0],
        ['code' => '2', 'tax_code' => '3440', 'base' => 33.33, 'porcentage' => 10.0],
    ]);

    expect($result[0]['value'])->toBe(1.0)
        ->and($result[1]['value'])->toBe(5.0)
        ->and($result[2]['value'])->toBe(3.33);
});

it('does not log when submitted value differs by 0.01 or less', function () {
    Log::spy();

    $result = (new RetentionTotalsCalculator)->recalculate([
        ['code' => '1', 'tax_code' => '312', 'base' => 100.0, 'porcentage' => 1.0, 'value' => 1.01],
    ], 55);

    expect($result[0]['value'])->toBe(1.0);
    Log::shouldNotHaveReceived('warning');
});

it('logs a warning when submitted value differs by more than 0.01 and uses the recalculated value', function () {
    Log::spy();

    $result = (new RetentionTotalsCalculator)->recalculate([
        ['code' => '1', 'tax_code' => '312', 'base' => 100.0, 'porcentage' => 1.0, 'value' => 99.0],
    ], 55);

    expect($result[0]['value'])->toBe(1.0);

    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
        return str_contains($message, 'Retention value mismatch')
            && $context['shop_id'] === 55
            && $context['tax_code'] === '312'
            && $context['submitted'] === 99.0
            && $context['calculated'] === 1.0;
    });
});

it('calculates without comparing or logging when no value was submitted', function () {
    Log::spy();

    $result = (new RetentionTotalsCalculator)->recalculate([
        ['code' => '1', 'tax_code' => '312', 'base' => 100.0, 'porcentage' => 1.0],
    ], 55);

    expect($result[0]['value'])->toBe(1.0)
        ->and($result[0])->not->toHaveKey('submitted');
    Log::shouldNotHaveReceived('warning');
});
