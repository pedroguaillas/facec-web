<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Models\Order\Order;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;

function resyncSequenceFixture(int $nextNumber, array $usedNumbers): EmisionPointSequence
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create(['store' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 100]);
    $voucherTypeId = VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id');

    foreach ($usedNumbers as $number) {
        Order::factory()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'voucher_type_id' => $voucherTypeId,
            'serie' => sprintf('001-100-%09d', $number),
        ]);
    }

    return EmisionPointSequence::updateOrCreate(
        ['emision_point_id' => $emisionPoint->id, 'voucher_type_id' => $voucherTypeId],
        ['next_number' => $nextNumber],
    );
}

test('sube next_number al máximo secuencial usado + 1 cuando quedó atrasado', function () {
    $sequence = resyncSequenceFixture(10, [9, 10, 25]);

    $this->artisan('vouchers:resync-sequences')->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe(26);
});

test('no baja una secuencia que ya está adelantada', function () {
    $sequence = resyncSequenceFixture(50, [9, 10]);

    $this->artisan('vouchers:resync-sequences')->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe(50);
});

test('--dry-run no modifica nada', function () {
    $sequence = resyncSequenceFixture(10, [25]);

    $this->artisan('vouchers:resync-sequences --dry-run')->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe(10);
});
