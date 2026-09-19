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
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function convertActingAsCompany(): Branch
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

function createProformaFor(Branch $branch, Product $product): Order
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $proformaTypeId = VoucherType::where('slug', VoucherTypes::VENTA_PROFORMA)->value('id');

    test()->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type_id' => $proformaTypeId,
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
    ])->assertCreated();

    return Order::whereHas('voucherType', fn ($q) => $q->where('slug', VoucherTypes::VENTA_PROFORMA))->latest('id')->first();
}

test('convertir una proforma crea una factura nueva desde el mismo punto de emisión y deja la proforma intacta', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);

    $response = $this->getJson(route('orders.convert-to-invoice', $proforma));

    $response->assertCreated();

    $invoiceId = $response->json('id');
    expect($invoiceId)->not->toBe($proforma->id);

    $invoice = Order::find($invoiceId);
    $proformaPrefix = substr($proforma->serie, 0, 8);

    expect($invoice->voucherType->slug)->toBe(VoucherTypes::VENTA_FACTURA)
        ->and($invoice->customer_id)->toBe($proforma->customer_id)
        ->and($invoice->orderitems)->toHaveCount(1)
        ->and(substr($invoice->serie, 0, 8))->toBe($proformaPrefix);

    $proforma->refresh();
    expect($proforma->voucherType->slug)->toBe(VoucherTypes::VENTA_PROFORMA);
});

test('una proforma puede convertirse mas de una vez, generando facturas independientes', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);

    $first = $this->getJson(route('orders.convert-to-invoice', $proforma))->assertCreated();
    $second = $this->getJson(route('orders.convert-to-invoice', $proforma))->assertCreated();

    expect($first->json('id'))->not->toBe($second->json('id'));

    $firstSequential = (int) substr($first->json('serie'), -9);
    $secondSequential = (int) substr($second->json('serie'), -9);

    expect($secondSequential)->toBe($firstSequential + 1);
});

test('no se puede convertir una orden que ya es factura', function () {
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);
    $invoiceTypeId = VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id');

    $storeResponse = $this->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type_id' => $invoiceTypeId,
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
    ])->assertCreated();

    $invoice = Order::find($storeResponse->json('id'));

    $response = $this->getJson(route('orders.convert-to-invoice', $invoice));

    $response->assertUnprocessable();
});

test('convertir siempre despacha ProcessVoucherJob (send fijo en true, no configurable)', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);

    $response = $this->getJson(route('orders.convert-to-invoice', $proforma));

    $response->assertCreated();
    Queue::assertPushed(ProcessVoucherJob::class);
});
