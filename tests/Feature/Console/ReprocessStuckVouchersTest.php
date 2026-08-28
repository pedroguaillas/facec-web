<?php

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order\Order;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Queue;

test('encola solo las orders no finales (por defecto, sin --type)', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    $stuck = Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::SAVED]);
    Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::AUTHORIZED]);
    Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::CANCELED]);

    $this->artisan('vouchers:reprocess-stuck')->assertSuccessful();

    Queue::assertPushed(ProcessVoucherJob::class, 1);
    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($stuck, $company) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'order'
            && (new ReflectionProperty($job, 'modelId'))->getValue($job) === $stuck->id
            && (new ReflectionProperty($job, 'companyId'))->getValue($job) === $company->id;
    });
});

test('--dry-run no encola nada', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::SAVED]);

    $this->artisan('vouchers:reprocess-stuck --dry-run')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('por defecto solo encola los creados/tocados hoy, --all incluye los de antes', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    $today = Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::SAVED]);
    // Viejo de verdad: created_at Y updated_at de ayer (no solo uno de los dos —
    // un lote subido antes de medianoche deja created_at de "ayer" pero updated_at
    // de "hoy" en el primer intento fallido, y ese caso sí debe encolarse).
    $old = Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::SAVED]);
    $old->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->saveQuietly();

    $this->artisan('vouchers:reprocess-stuck')->assertSuccessful();

    Queue::assertPushed(ProcessVoucherJob::class, 1);
    Queue::assertPushed(ProcessVoucherJob::class, fn (ProcessVoucherJob $job) => (new ReflectionProperty($job, 'modelId'))->getValue($job) === $today->id);

    Queue::fake();
    $this->artisan('vouchers:reprocess-stuck --all')->assertSuccessful();
    Queue::assertPushed(ProcessVoucherJob::class, 2);
});

test('creado antes de medianoche pero tocado hoy (updated_at) igual se encola', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    $order = Order::factory()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'state' => VoucherStates::SAVED]);
    // Simula un lote subido antes de medianoche: created_at queda "ayer", pero el
    // primer intento fallido de anoche/hoy temprano actualiza updated_at a "hoy".
    $order->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    $this->artisan('vouchers:reprocess-stuck')->assertSuccessful();

    Queue::assertPushed(ProcessVoucherJob::class, 1);
});

test('tipo desconocido reporta error y no encola', function () {
    Queue::fake();

    $this->artisan('vouchers:reprocess-stuck --type=nope')->assertSuccessful();

    Queue::assertNothingPushed();
});
