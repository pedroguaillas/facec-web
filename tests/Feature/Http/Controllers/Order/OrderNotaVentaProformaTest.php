<?php

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function notaVentaActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['active_voucher' => true]);
    $branch = Branch::factory()->for($company)->create(['store' => 1]);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    return $branch;
}

function notaVentaOrderPayload(Branch $branch, Product $product, string $slug, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    return array_merge([
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type_id' => VoucherType::where('slug', $slug)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'send' => true,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'iva' => 2,
        ]],
        'aditionals' => [],
    ], $overrides);
}

test('crear una nota de venta no despacha ProcessVoucherJob aunque send sea true', function () {
    Queue::fake();
    $branch = notaVentaActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);

    $response = $this->postJson(route('orders.store'), notaVentaOrderPayload($branch, $product, VoucherTypes::VENTA_NOTA_VENTA));

    $response->assertCreated();
    Queue::assertNotPushed(ProcessVoucherJob::class);
});

test('crear una proforma no despacha ProcessVoucherJob aunque send sea true', function () {
    Queue::fake();
    $branch = notaVentaActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);

    $response = $this->postJson(route('orders.store'), notaVentaOrderPayload($branch, $product, VoucherTypes::VENTA_PROFORMA));

    $response->assertCreated();
    Queue::assertNotPushed(ProcessVoucherJob::class);
});

test('nota de venta y proforma llevan secuencial propio, independiente de factura', function () {
    $branch = notaVentaActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);

    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    $payloadFor = fn (string $slug) => [
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type_id' => VoucherType::where('slug', $slug)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'iva' => 2,
        ]],
        'aditionals' => [],
    ];

    $this->postJson(route('orders.store'), $payloadFor(VoucherTypes::VENTA_FACTURA))->assertCreated();
    $this->postJson(route('orders.store'), $payloadFor(VoucherTypes::VENTA_FACTURA))->assertCreated();
    $this->postJson(route('orders.store'), $payloadFor(VoucherTypes::VENTA_PROFORMA))->assertCreated();

    $facturas = Order::whereHas('voucherType', fn ($q) => $q->where('slug', VoucherTypes::VENTA_FACTURA))->pluck('serie')->sort()->values();
    $proformas = Order::whereHas('voucherType', fn ($q) => $q->where('slug', VoucherTypes::VENTA_PROFORMA))->pluck('serie');

    expect($facturas->toArray())->toBe(['001-001-000000001', '001-001-000000002'])
        ->and($proformas->first())->toBe('001-001-000000001');
});

test('orders.process rechaza con 422 un comprobante que no requiere ciclo electronico (proforma)', function () {
    $branch = notaVentaActingAsCompany();
    $proformaTypeId = VoucherType::where('slug', VoucherTypes::VENTA_PROFORMA)->value('id');

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $proformaTypeId,
        'state' => VoucherStates::SAVED,
    ]);

    $response = $this->getJson(route('orders.process', $order));

    $response->assertStatus(422)->assertJson(['succes' => false]);
});
