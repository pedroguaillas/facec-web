<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\Services\VoucherSequenceService;
use App\StaticClasses\VoucherTypes;

function actingAsCompanyUserWithPoint(): EmisionPoint
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $branch = Branch::factory()->for($company)->create(['store' => 1]);

    return EmisionPoint::factory()->create(['branch_id' => $branch->id, 'point' => 100, 'invoice' => 17050]);
}

function invoiceSequence(EmisionPoint $point): ?EmisionPointSequence
{
    return EmisionPointSequence::where('emision_point_id', $point->id)
        ->where('voucher_type_id', VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'))
        ->first();
}

test('index muestra el next_number real de emision_point_sequences, no la columna legacy', function () {
    $point = actingAsCompanyUserWithPoint();
    EmisionPointSequence::create([
        'emision_point_id' => $point->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'next_number' => 15052,
    ]);

    $response = $this->getJson(route('settings.points.index', $point->branch_id));

    $response->assertOk();
    expect(collect($response->json())->firstWhere('id', $point->id)['invoice'])->toBe(15052);
});

test('update escribe el secuencial en emision_point_sequences y la siguiente factura lo usa', function () {
    $point = actingAsCompanyUserWithPoint();
    EmisionPointSequence::create([
        'emision_point_id' => $point->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'next_number' => 15052,
    ]);

    $response = $this->putJson(route('settings.points.update', $point), ['invoice' => 17051]);

    $response->assertOk()->assertJsonPath('invoice', 17051);
    expect(invoiceSequence($point)->next_number)->toBe(17051);

    $serie = app(VoucherSequenceService::class)->generateSerie($point->fresh(), VoucherTypes::VENTA_FACTURA);
    expect($serie)->toBe('001-100-000017051');
});

test('update crea la fila de secuencia si todavía no existe', function () {
    $point = actingAsCompanyUserWithPoint();

    $this->putJson(route('settings.points.update', $point), ['invoice' => 40])->assertOk();

    expect(invoiceSequence($point)->next_number)->toBe(40);
});

test('store crea las secuencias de cada tipo con los valores enviados', function () {
    $point = actingAsCompanyUserWithPoint();

    $response = $this->postJson(route('settings.points.store'), [
        'branch_id' => $point->branch_id,
        'point' => 2,
        'invoice' => 10,
        'creditnote' => 3,
        'retention' => 4,
        'referralguide' => 5,
        'settlementonpurchase' => 6,
    ]);

    $response->assertCreated();
    expect(EmisionPointSequence::where('emision_point_id', $response->json('id'))->count())->toBe(5);
});
