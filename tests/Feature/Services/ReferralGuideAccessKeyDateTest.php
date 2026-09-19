<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ReferralGuide\ReferralGuide;
use App\Xml\ReferralGuideBuilder;

test('la clave de acceso usa la fecha de emisión (date), no la de inicio de transporte (date_start)', function () {
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

    // Se emite la guía el 01/03/2026, pero el transporte arranca 5 días después.
    $guide = ReferralGuide::create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'serie' => '001-001-000000001',
        'date' => '2026-03-01',
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => '2026-03-06',
        'date_end' => '2026-03-07',
        'reason_transfer' => 'Venta',
    ]);
    $guide->ca_name = 'Transportista Test';
    $guide->ca_identication = '0999999999';
    $guide->license_plate = 'ABC-1234';

    $xml = (new ReferralGuideBuilder($company, $guide, collect([])))->build();

    preg_match('/<claveAcceso>(\d{8})/', $xml, $matches);

    expect($matches[1])->toBe('01032026'); // ddmmyyyy de `date`, no de `date_start` (06032026)
});
