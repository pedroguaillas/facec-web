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
use App\Services\Order\OrderUpdateService;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherTypes;
use App\Xml\AccessKey;
use Database\Seeders\MethodOfPaymentSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(MethodOfPaymentSeeder::class);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 4, 'percentage' => 15, 'state' => 'active'],
    ]);

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $this->company = Company::factory()->plan(PlanTypes::FINANCIERO)->create(['ruc' => '0102030405001', 'enviroment_type' => 1]);
    $branch = Branch::factory()->for($this->company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $this->company->id]);
    $this->actingAs($user);

    $this->customer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '0102030405', 'type_identification' => 'cédula']);
    $this->point = EmisionPoint::factory()->for($branch)->create();
    $this->product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 4]);
});

function storeOrderWithAccessKey(string $voucherSlug, string $date = '2026-10-09'): Order
{
    test()->postJson(route('orders.store'), [
        'customer_id' => test()->customer->id,
        'total' => 115,
        'voucher_type_id' => VoucherType::where('slug', $voucherSlug)->value('id'),
        'point_id' => test()->point->id,
        'date' => $date,
        'pay_method' => 20,
        'payment_type' => PaymentTypes::CREDITO,
        'due_date' => '2026-10-24',
        'products' => [['product_id' => test()->product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4]],
        'aditionals' => [],
    ])->assertSuccessful();

    return Order::latest('id')->firstOrFail();
}

test('la factura de venta se guarda con su clave de acceso', function () {
    $order = storeOrderWithAccessKey(VoucherTypes::VENTA_FACTURA);

    $expected = '09102026'.'01'.'0102030405001'.'1'.str_replace('-', '', $order->serie).'123456781';

    expect($order->authorization)->toHaveLength(49)
        ->and($order->authorization)->toBe($expected.AccessKey::checkDigit($expected));
});

test('la nota de venta no genera clave de acceso', function () {
    $order = storeOrderWithAccessKey(VoucherTypes::VENTA_NOTA_VENTA);

    expect($order->authorization)->toBeEmpty();
});

test('editar la fecha recalcula la clave de acceso', function () {
    $order = storeOrderWithAccessKey(VoucherTypes::VENTA_FACTURA);

    app(OrderUpdateService::class)->updateOrder($order, [
        'customer_id' => $this->customer->id,
        'date' => '2026-10-15',
        'products' => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4]],
        'aditionals' => [],
    ]);

    expect(substr($order->fresh()->authorization, 0, 8))->toBe('15102026');
});
