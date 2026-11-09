<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order\Order;
use App\Services\VoucherLifecycleService;
use App\Services\XmlSignerService;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

function signValidityFixture(?DateTimeInterface $signValidTo): array
{
    $company = Company::factory()->create([
        'cert_dir' => 'empresa.p12',
        'pass_cert' => 'secret',
        'sign_valid_to' => $signValidTo,
    ]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'state' => VoucherStates::SAVED,
        'date' => now()->toDateString(),
        'extra_detail' => 'detalle previo',
    ]);
    $xml = '<factura><claveAcceso>'.str_repeat('1', 49).'</claveAcceso></factura>';

    return [$company, $order, $xml];
}

test('no firma el comprobante si la firma electrónica está caducada', function () {
    [$company, $order, $xml] = signValidityFixture(now()->subDay());

    $signer = Mockery::mock(XmlSignerService::class);
    $signer->shouldNotReceive('sign');
    app()->instance(XmlSignerService::class, $signer);

    app(VoucherLifecycleService::class)->saveAndSign($company, $order, $xml);

    $order->refresh();
    expect($order->state)->toBe(VoucherStates::SAVED)
        ->and($order->extra_detail)->toBe(VoucherLifecycleService::EXPIRED_SIGNATURE_DETAIL)
        ->and($order->xml)->toContain(VoucherStates::SAVED);
});

test('firma el comprobante si la firma electrónica está vigente o sin fecha', function (?DateTimeInterface $signValidTo) {
    [$company, $order, $xml] = signValidityFixture($signValidTo);

    $signer = Mockery::mock(XmlSignerService::class);
    $signer->shouldReceive('sign')->once()->andReturnTrue();
    app()->instance(XmlSignerService::class, $signer);

    app(VoucherLifecycleService::class)->saveAndSign($company, $order, $xml);

    $order->refresh();
    expect($order->state)->toBe(VoucherStates::SIGNED)
        ->and($order->extra_detail)->toBeNull();
})->with([
    'vigente' => fn () => now()->addMonth(),
    'sin fecha' => null,
]);
