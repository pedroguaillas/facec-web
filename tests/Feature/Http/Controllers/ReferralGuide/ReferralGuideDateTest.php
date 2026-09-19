<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\User;
use App\Models\UserType;

function referralGuideActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $branch;
}

test('crear una guía de remisión sin date la guarda igual, con la fecha actual del servidor', function () {
    $branch = referralGuideActingAsCompany();
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $carrier = Carrier::create([
        'branch_id' => $branch->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);

    $response = $this->postJson(route('referralguides.store'), [
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'point_id' => $emisionPoint->id,
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
        'products' => [],
    ]);

    $response->assertCreated();
    $guide = ReferralGuide::find($response->json('id'));
    expect($guide->date)->toBe(now()->format('Y-m-d'));
});

test('un date enviado por el cliente se ignora — siempre se guarda la fecha del servidor', function () {
    $branch = referralGuideActingAsCompany();
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $carrier = Carrier::create([
        'branch_id' => $branch->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);

    $response = $this->postJson(route('referralguides.store'), [
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'point_id' => $emisionPoint->id,
        'date' => '2020-01-01',
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
        'products' => [],
    ]);

    $response->assertCreated();
    $guide = ReferralGuide::find($response->json('id'));
    expect($guide->date)->toBe(now()->format('Y-m-d'))
        ->not->toBe('2020-01-01');
});

test('el listado devuelve la fecha de emisión guardada', function () {
    $branch = referralGuideActingAsCompany();
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $carrier = Carrier::create([
        'branch_id' => $branch->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);

    $this->postJson(route('referralguides.store'), [
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'point_id' => $emisionPoint->id,
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
        'products' => [],
    ])->assertCreated();

    $index = $this->getJson(route('referralguides.index'));
    $index->assertOk();
    expect($index->json('data.0.atts.date'))->toBe(now()->format('d-m-Y'));
});
