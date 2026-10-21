<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ReferralGuide\ReferralGuide;
use App\Services\ReferralGuide\ReferralGuideLifecycleService;
use App\Services\SriSoapService;
use App\StaticClasses\VoucherStates;

/**
 * @return array{0: Company, 1: ReferralGuide}
 */
function signedReferralGuide(string $date): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create(['store' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $carrier = Carrier::create([
        'branch_id' => $branch->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);

    $guide = ReferralGuide::create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'serie' => '001-001-000000001',
        'date' => $date,
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => $date,
        'date_end' => $date,
        'reason_transfer' => 'Venta',
        'state' => VoucherStates::SIGNED,
    ]);

    return [$company, $guide];
}

test('una guía firmada en un día anterior no se envía: pasa a hoy y vuelve a CREADO', function () {
    [$company, $guide] = signedReferralGuide(now()->subDay()->format('Y-m-d'));

    $soap = Mockery::mock(SriSoapService::class);
    $soap->shouldNotReceive('send');
    app()->instance(SriSoapService::class, $soap);

    app(ReferralGuideLifecycleService::class)->process($guide, $company);

    $guide->refresh();

    expect($guide->date)->toBe(now()->format('Y-m-d'))
        ->and($guide->state)->toBe(VoucherStates::SAVED)
        ->and($guide->date_start)->toBe(now()->subDay()->format('Y-m-d'));
});

test('una guía firmada hoy se envía al SRI', function () {
    [$company, $guide] = signedReferralGuide(now()->format('Y-m-d'));

    $soap = Mockery::mock(SriSoapService::class);
    $soap->shouldReceive('send')->once();
    app()->instance(SriSoapService::class, $soap);

    app(ReferralGuideLifecycleService::class)->process($guide, $company);

    expect($guide->fresh()->state)->toBe(VoucherStates::SIGNED);
});
