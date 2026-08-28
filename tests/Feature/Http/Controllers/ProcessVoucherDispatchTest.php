<?php

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Queue;

function actingAsVoucherCompany(): Company
{
    $userType = UserType::firstOrCreate(['type' => 'client']);

    $company = Company::factory()->create(['active_voucher' => true]);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $company;
}

test('orders.process encola ProcessVoucherJob con tipo order y responde de inmediato', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SAVED]);

    $response = $this->getJson(route('orders.process', $order));

    $response->assertOk()->assertJson(['succes' => true]);

    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($order, $company) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'order'
            && (new ReflectionProperty($job, 'modelId'))->getValue($job) === $order->id
            && (new ReflectionProperty($job, 'companyId'))->getValue($job) === $company->id;
    });
});

test('orders.process no encola si la empresa no tiene la facturación electrónica activa', function () {
    Queue::fake();

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['active_voucher' => false]);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SAVED]);

    $response = $this->getJson(route('orders.process', $order));

    $response->assertStatus(422)->assertJson(['succes' => false]);
    Queue::assertNotPushed(ProcessVoucherJob::class);
});

test('orders.process no encola si el comprobante ya está AUTORIZADO', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED]);

    $response = $this->getJson(route('orders.process', $order));

    $response->assertStatus(422)->assertJson(['succes' => false]);
    Queue::assertNotPushed(ProcessVoucherJob::class);
});

test('orders.process no encola si el comprobante está PENDIENTE DE ANULAR', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $order = Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::PENDING_CANCELATION]);

    $response = $this->getJson(route('orders.process', $order));

    $response->assertStatus(422)->assertJson(['succes' => false]);
    Queue::assertNotPushed(ProcessVoucherJob::class);
});

test('shops.process encola ProcessVoucherJob con tipo shop', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $shop = Shop::factory()->create(['branch_id' => $branch->id, 'voucher_type' => 3, 'state' => VoucherStates::SAVED]);

    $response = $this->getJson(route('shops.process', $shop));

    $response->assertOk()->assertJson(['succes' => true]);

    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($shop) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'shop'
            && (new ReflectionProperty($job, 'modelId'))->getValue($job) === $shop->id;
    });
});

test('retentions.process encola ProcessVoucherJob con tipo shop_retention', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'serie_retencion' => '001-001-000000001',
        'state_retencion' => VoucherStates::SAVED,
    ]);

    $response = $this->getJson(route('retentions.process', $shop));

    $response->assertOk()->assertJson(['succes' => true]);

    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($shop) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'shop_retention'
            && (new ReflectionProperty($job, 'modelId'))->getValue($job) === $shop->id;
    });
});

test('referralguides.process encola ProcessVoucherJob con tipo referral_guide', function () {
    Queue::fake();

    $company = actingAsVoucherCompany();
    $branch = Branch::factory()->for($company)->create();
    $carrier = Carrier::create([
        'branch_id' => $branch->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);

    $referralguide = ReferralGuide::create([
        'branch_id' => $branch->id,
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'carrier_id' => $carrier->id,
        'serie' => '001-001-000000001',
        'address_from' => 'Origen 123',
        'address_to' => 'Destino 456',
        'date_start' => now()->toDateString(),
        'date_end' => now()->addDay()->toDateString(),
        'reason_transfer' => 'Venta',
        'state' => VoucherStates::SAVED,
    ]);

    $response = $this->getJson(route('referralguides.process', $referralguide));

    $response->assertOk()->assertJson(['succes' => true]);

    Queue::assertPushed(ProcessVoucherJob::class, function (ProcessVoucherJob $job) use ($referralguide) {
        return (new ReflectionProperty($job, 'voucherType'))->getValue($job) === 'referral_guide'
            && (new ReflectionProperty($job, 'modelId'))->getValue($job) === $referralguide->id;
    });
});
