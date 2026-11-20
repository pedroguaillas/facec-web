<?php

use App\Models\Company;
use App\Xml\AccessKey;
use App\Xml\InvoiceLotBuilder;
use Carbon\CarbonImmutable;

test('la clave de acceso del lote es la de factura con fecha de hoy y la serie del lote', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
    $company = Company::factory()->create(['ruc' => '0102030405001', 'enviroment_type' => 1]);

    $key = (new InvoiceLotBuilder($company, (object) ['serie' => '001-002-000000015'], []))->getAccessKey();

    $base = '20112026'.'01'.'0102030405001'.'1'.'001002000000015'.'123456781';

    expect($key)->toHaveLength(49)
        ->toBe($base.AccessKey::checkDigit($base));
});
