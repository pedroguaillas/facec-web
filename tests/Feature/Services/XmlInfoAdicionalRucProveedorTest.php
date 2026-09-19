<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use App\Xml\ReferralGuideBuilder;
use App\Xml\RetentionBuilder;
use App\Xml\SettlementOnPurchaseBuilder;

beforeEach(function () {
    config(['services.order.ruc_proveedor' => '1234567890001']);
});

function xmlCompanyAndBranch(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create(['store' => 1]);

    return [$company, $branch];
}

test('liquidación de compra incluye RUC Proveedor en infoAdicional del XML', function () {
    [$company, $branch] = xmlCompanyAndBranch();
    $liquidacionId = VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id');

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $liquidacionId,
        'serie' => '001-001-000000001',
    ]);
    $shop->type_identification = 'cédula';
    $shop->name = 'Proveedor Test';
    $shop->identication = '0650243678';

    $xml = (new SettlementOnPurchaseBuilder($company, $shop, collect([])))->build();

    expect($xml)->toContain('<infoAdicional>')
        ->toContain('<campoAdicional nombre="RUC Proveedor">1234567890001</campoAdicional>');
});

test('retención incluye RUC Proveedor en infoAdicional del XML', function () {
    [$company, $branch] = xmlCompanyAndBranch();
    $facturaId = VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id');

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $facturaId,
        'serie' => '001-001-000000001',
        'serie_retencion' => '001-001-000000001',
        'date_retention' => now()->format('Y-m-d'),
    ]);
    $shop->type_identification = 'ruc';
    $shop->name = 'Proveedor Test';
    $shop->identication = '1234567890001';

    $xml = (new RetentionBuilder($company, $shop))->build();

    expect($xml)->toContain('<infoAdicional>')
        ->toContain('<campoAdicional nombre="RUC Proveedor">1234567890001</campoAdicional>');
});

test('guía de remisión incluye RUC Proveedor en infoAdicional del XML', function () {
    [$company, $branch] = xmlCompanyAndBranch();
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
        'date' => now()->format('Y-m-d'),
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
    ]);
    $guide->ca_name = 'Transportista Test';
    $guide->ca_identication = '0650243678';
    $guide->license_plate = 'ABC-1234';

    $xml = (new ReferralGuideBuilder($company, $guide, collect([])))->build();

    expect($xml)->toContain('<infoAdicional>')
        ->toContain('<campoAdicional nombre="RUC Proveedor">1234567890001</campoAdicional>');
});
