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

/**
 * @return array<string, mixed>
 */
function referralGuidePayload(Branch $branch, array $overrides = []): array
{
    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'carrier_id' => Carrier::create([
            'branch_id' => $branch->id,
            'type_identification' => '05',
            'identication' => '0999999999',
            'name' => 'Transportista Test',
            'license_plate' => 'ABC-1234',
        ])->id,
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
        'products' => [],
        ...$overrides,
    ];
}

test('el inicio de transporte es la fecha actual: el date_start del cliente se ignora', function () {
    $branch = referralGuideActingAsCompany();

    $id = $this->postJson(route('referralguides.store'), referralGuidePayload($branch, ['date_start' => now()->subDays(3)->format('Y-m-d')]))
        ->assertCreated()
        ->json('id');

    expect(ReferralGuide::find($id)->date_start)->toBe(now()->format('Y-m-d'));
});

test('la fecha fin no puede ser anterior a hoy al crear ni al inicio guardado al editar', function () {
    $branch = referralGuideActingAsCompany();

    $payload = referralGuidePayload($branch);

    $this->postJson(route('referralguides.store'), [...$payload, 'date_end' => now()->subDay()->format('Y-m-d')])
        ->assertJsonValidationErrors('date_end');
    $guide = ReferralGuide::find($this->postJson(route('referralguides.store'), $payload)->assertCreated()->json('id'));
    $guide->update(['date_start' => now()->subDays(5)->format('Y-m-d')]);

    $this->putJson(route('referralguides.update', $guide), [...$payload, 'date_end' => now()->subDays(2)->format('Y-m-d'), 'date_start' => now()->format('Y-m-d')])
        ->assertOk();

    expect($guide->fresh()->date_start)->toBe(now()->subDays(5)->format('Y-m-d'))
        ->and($guide->fresh()->date_end)->toBe(now()->subDays(2)->format('Y-m-d'));
    $this->putJson(route('referralguides.update', $guide), [...$payload, 'date_end' => now()->subDays(6)->format('Y-m-d')])
        ->assertJsonValidationErrors('date_end');
});
