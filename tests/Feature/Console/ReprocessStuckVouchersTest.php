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

test('tipo desconocido reporta error y no encola', function () {
    Queue::fake();

    $this->artisan('vouchers:reprocess-stuck --type=nope')->assertSuccessful();

    Queue::assertNothingPushed();
});
