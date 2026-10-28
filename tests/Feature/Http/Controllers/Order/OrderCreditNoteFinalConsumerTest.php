<?php

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

function creditNoteActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    return $branch;
}

function creditNoteOrderPayload(Branch $branch, Customer $customer, string $voucherTypeSlug): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'type_product' => Product::TYPE_PRODUCT, 'iva' => 2]);

    return [
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type_id' => VoucherType::where('slug', $voucherTypeSlug)->value('id'),
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
}

test('orders.store rechaza nota de crédito a consumidor final', function () {
    $branch = creditNoteActingAsCompany();
    $finalConsumer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '9999999999999']);

    $this->postJson(route('orders.store'), creditNoteOrderPayload($branch, $finalConsumer, VoucherTypes::VENTA_NOTA_CREDITO))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id' => 'No se puede emitir una nota de crédito a consumidor final']);
});

test('orders.store permite nota de crédito a un cliente identificado', function () {
    $branch = creditNoteActingAsCompany();
    $customer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '0102030405']);

    $this->postJson(route('orders.store'), creditNoteOrderPayload($branch, $customer, VoucherTypes::VENTA_NOTA_CREDITO))
        ->assertCreated();
});

test('orders.store permite factura a consumidor final', function () {
    $branch = creditNoteActingAsCompany();
    $finalConsumer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '9999999999999']);

    $this->postJson(route('orders.store'), creditNoteOrderPayload($branch, $finalConsumer, VoucherTypes::VENTA_FACTURA))
        ->assertCreated();
});

test('orders.update rechaza nota de crédito a consumidor final', function () {
    $branch = creditNoteActingAsCompany();
    $finalConsumer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '9999999999999']);
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $finalConsumer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id'),
    ]);

    $payload = creditNoteOrderPayload($branch, $finalConsumer, VoucherTypes::VENTA_NOTA_CREDITO);

    $this->putJson(route('orders.update', $order), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id' => 'No se puede emitir una nota de crédito a consumidor final']);
});
