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
    $emisionPoint = EmisionPoint::firstOrCreate(['branch_id' => $branch->id, 'point' => 1], EmisionPoint::factory()->make()->only(['invoice', 'creditnote', 'retention', 'referralguide', 'settlementonpurchase', 'recognition']));
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

test('una proforma ya convertida no se vuelve a convertir mientras su factura esté vigente', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);

    $first = $this->getJson(route('orders.convert-to-invoice', $proforma))->assertCreated();
    $invoice = Order::with('voucherType')->findOrFail($first->json('id'));

    expect($invoice->proforma_id)->toBe($proforma->id);

    $this->getJson(route('orders.convert-to-invoice', $proforma))
        ->assertUnprocessable()
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', "Esta proforma ya fue convertida en la factura {$invoice->voucherType->abbreviation} {$invoice->serie}.");

    expect(Order::where('proforma_id', $proforma->id)->count())->toBe(1);
});

test('si la factura de la proforma se anuló, la proforma se puede convertir de nuevo', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);

    $firstId = $this->getJson(route('orders.convert-to-invoice', $proforma))->assertCreated()->json('id');
    Order::whereKey($firstId)->update(['state' => VoucherStates::CANCELED]);

    $secondId = $this->getJson(route('orders.convert-to-invoice', $proforma))->assertCreated()->json('id');

    expect($secondId)->not->toBe($firstId)
        ->and(Order::findOrFail($secondId)->proforma_id)->toBe($proforma->id);
});

test('el listado y el edit devuelven la factura vigente de la proforma sin consultas por orden', function () {
    Queue::fake();
    $branch = convertActingAsCompany();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $proforma = createProformaFor($branch, $product);
    $invoice = Order::with('voucherType')->findOrFail($this->getJson(route('orders.convert-to-invoice', $proforma))->json('id'));
    $expected = ['id' => $invoice->id, 'serie' => "{$invoice->voucherType->abbreviation} {$invoice->serie}", 'state' => $invoice->state];

    $rows = collect($this->getJson(route('orders.index'))->assertOk()->json('data'))->keyBy('id');

    expect($rows[$proforma->id]['atts']['converted_invoice'])->toBe($expected)
        ->and($rows[$invoice->id]['atts']['converted_invoice'])->toBeNull();
    $this->getJson(route('orders.edit', $proforma))->assertOk()->assertJsonPath('converted_invoice', $expected);

    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('orders.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $before = $queries();
    $this->getJson(route('orders.convert-to-invoice', createProformaFor($branch, $product)))->assertCreated();

    expect($queries())->toBe($before);
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
