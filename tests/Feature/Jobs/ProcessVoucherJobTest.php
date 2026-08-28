<?php

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order\Order;
use App\Services\Order\OrderLifecycleService;
use App\StaticClasses\VoucherStates;

test('libera el job con backoff si el estado sigue pendiente tras process()', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SIGNED]);

    // process() no avanza el estado (simula que send() no obtuvo respuesta del SRI).
    $this->mock(OrderLifecycleService::class)
        ->shouldReceive('process')
        ->once()
        ->withArgs(fn (Order $model, Company $c) => $model->id === $order->id && $c->id === $company->id);

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 2;

    $job->handle();

    $job->assertReleased(60); // BACKOFF[attempt=2 -> index 1] = 60
});

test('libera el job si el comprobante queda DEVUELTA (el SRI lo rechazó y hay que reconstruir/reenviar)', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SAVED]);

    // process() reconstruye el XML, lo firma, lo envía, y el SRI lo devuelve (DEVUELTA).
    $this->mock(OrderLifecycleService::class)
        ->shouldReceive('process')
        ->once()
        ->andReturnUsing(function (Order $model) {
            $model->update(['state' => VoucherStates::RETURNED]);
        });

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 1;

    $job->handle();

    $job->assertReleased(30);
});

test('no libera el job cuando el comprobante llega a AUTORIZADO', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SIGNED]);

    $this->mock(OrderLifecycleService::class)
        ->shouldReceive('process')
        ->once()
        ->andReturnUsing(function (Order $model) {
            $model->update(['state' => VoucherStates::AUTHORIZED]);
        });

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 1;

    $job->handle();

    $job->assertNotReleased();
});

test('no libera el job cuando se agotaron los intentos', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SIGNED]);

    $this->mock(OrderLifecycleService::class)->shouldReceive('process')->once();

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 8; // == $tries

    $job->handle();

    $job->assertNotReleased();
});

test('no procesa un comprobante ya finalizado (AUTORIZADO)', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED]);

    $this->mock(OrderLifecycleService::class)->shouldNotReceive('process');

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
});

test('no procesa un comprobante PENDIENTE DE ANULAR', function () {
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::PENDING_CANCELATION]);

    $this->mock(OrderLifecycleService::class)->shouldNotReceive('process');

    $job = new ProcessVoucherJob('order', $order->id, $company->id);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
});
