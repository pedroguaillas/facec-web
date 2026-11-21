<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Holiday;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Treasury\BankAccount;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Database\Seeders\MethodOfPaymentSeeder;
use Illuminate\Support\Facades\DB;

function differentiatedIvaBranch(bool $base8 = true): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['base8' => $base8]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 0, 'percentage' => 0, 'state' => 'active'],
        ['code' => 4, 'percentage' => 15, 'state' => 'active'],
        ['code' => 8, 'percentage' => 8, 'state' => 'inactive'],
    ]);

    return $branch;
}

/**
 * @param  array<int, array{product: Product, iva: int}>  $lines
 * @return array<string, mixed>
 */
function differentiatedIvaPayload(Branch $branch, array $lines, string $date): array
{
    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'total' => 0,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => $date,
        'products' => array_map(fn (array $line) => [
            'product_id' => $line['product']->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => $line['iva'],
        ], $lines),
        'aditionals' => [],
    ];
}

function differentiatedIvaProduct(Branch $branch, bool $differentiated = true, int $iva = 4): Product
{
    return Product::factory()->create([
        'branch_id' => $branch->id,
        'iva' => $iva,
        'differentiated_iva' => $differentiated,
    ]);
}

test('en feriado la venta graba con 8% los productos marcados con IVA diferenciado', function () {
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $differentiated = differentiatedIvaProduct($branch);
    $regular = differentiatedIvaProduct($branch, differentiated: false);

    $this->postJson(route('orders.store'), differentiatedIvaPayload($branch, [
        ['product' => $differentiated, 'iva' => 4],
        ['product' => $regular, 'iva' => 4],
    ], '2026-11-03'))->assertSuccessful();

    $order = Order::latest('id')->firstOrFail();

    expect(OrderItem::where('order_id', $order->id)->pluck('iva', 'product_id')->all())
        ->toEqual([$differentiated->id => 8, $regular->id => 4])
        ->and($order->base8)->toBe(100.0)
        ->and($order->iva8)->toBe(8.0)
        ->and($order->base15)->toBe(100.0)
        ->and($order->iva15)->toBe(15.0)
        ->and($order->total)->toBe(223.0);
});

test('fuera del feriado se graba con la tarifa general aunque venga 8%', function () {
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $product = differentiatedIvaProduct($branch);

    $this->postJson(route('orders.store'), differentiatedIvaPayload($branch, [
        ['product' => $product, 'iva' => 8],
    ], '2026-11-04'))->assertSuccessful();

    $order = Order::latest('id')->firstOrFail();

    expect(OrderItem::where('order_id', $order->id)->value('iva'))->toBe(4)
        ->and($order->base8)->toBe(0.0)
        ->and($order->iva15)->toBe(15.0);
});

test('una empresa sin base8 no aplica IVA diferenciado en feriado', function () {
    $branch = differentiatedIvaBranch(base8: false);
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $product = differentiatedIvaProduct($branch);

    $this->postJson(route('orders.store'), differentiatedIvaPayload($branch, [
        ['product' => $product, 'iva' => 4],
    ], '2026-11-02'))->assertSuccessful();

    expect(OrderItem::latest('id')->value('iva'))->toBe(4);
});

test('productos con IVA 0% no cambian en feriado', function () {
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $product = differentiatedIvaProduct($branch, iva: 0);

    $this->postJson(route('orders.store'), differentiatedIvaPayload($branch, [
        ['product' => $product, 'iva' => 0],
    ], '2026-11-01'))->assertSuccessful();

    expect(OrderItem::latest('id')->value('iva'))->toBe(0);
});

test('al editar una venta en feriado se recalcula el IVA diferenciado', function () {
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $product = differentiatedIvaProduct($branch);
    $order = Order::factory()->create(['branch_id' => $branch->id, 'date' => '2026-11-02']);

    $this->putJson(route('orders.update', $order), [
        'total' => 0,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => 4,
        ]],
        'aditionals' => [],
    ])->assertSuccessful();

    expect(OrderItem::where('order_id', $order->id)->value('iva'))->toBe(8)
        ->and($order->fresh()->iva8)->toBe(8.0);
});

test('los datos para crear venta informan el feriado vigente', function () {
    differentiatedIvaBranch();
    $holiday = Holiday::factory()->create(['name' => 'Carnaval', 'starts_at' => today(), 'ends_at' => today()]);

    $this->getJson(route('orders.create'))
        ->assertOk()
        ->assertJsonPath('differentiated_iva', true)
        ->assertJsonPath('holiday.id', $holiday->id)
        ->assertJsonPath('holiday.name', 'Carnaval')
        ->assertJsonPath('holidays.0.id', $holiday->id);
});

test('los datos para crear venta listan feriados recientes y próximos, no los viejos', function () {
    differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonths(3)]);
    $next = Holiday::factory()->create(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDay()]);

    $this->getJson(route('orders.create'))
        ->assertOk()
        ->assertJsonCount(1, 'holidays')
        ->assertJsonPath('holidays.0.id', $next->id)
        ->assertJsonPath('holidays.0.starts_at', $next->starts_at->toDateString());
});

test('el formulario de producto informa base8 y el detalle de venta trae differentiated_iva por ítem', function () {
    $branch = differentiatedIvaBranch();
    $product = differentiatedIvaProduct($branch);
    $order = Order::factory()->create(['branch_id' => $branch->id]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'iva' => 4]);

    $this->getJson(route('products.create'))->assertOk()->assertJsonPath('base8', true);

    $this->getJson(route('orders.edit', $order))
        ->assertOk()
        ->assertJsonPath('order_items.0.differentiated_iva', true)
        ->assertJsonPath('products.0.differentiated_iva', true);
});

test('sin feriado vigente los datos para crear venta no informan IVA diferenciado', function () {
    differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()]);

    $this->getJson(route('orders.create'))
        ->assertOk()
        ->assertJsonPath('differentiated_iva', false)
        ->assertJsonPath('holiday', null);
});

test('solo una empresa con base8 puede marcar productos con IVA diferenciado', function (bool $base8, int $status) {
    differentiatedIvaBranch(base8: $base8);

    $this->postJson(route('products.store'), [
        'code' => 'TUR-1',
        'type_product' => 2,
        'name' => 'Hospedaje',
        'price1' => 50,
        'iva' => 4,
        'differentiated_iva' => true,
    ])->assertStatus($status);

    if ($status === 201) {
        expect(Product::where('code', 'TUR-1')->value('differentiated_iva'))->toBeTrue();
    }
})->with([
    'con base8' => [true, 201],
    'sin base8' => [false, 422],
]);

test('en feriado, si el total recalculado no cuadra con varias formas de pago, la venta se rechaza', function () {
    $this->seed(MethodOfPaymentSeeder::class);
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
    $bank = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
    $contado = fn (int $payMethod, float $amount) => [
        'pay_method' => $payMethod,
        'payment_type' => PaymentTypes::CONTADO,
        'amount' => $amount,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $bank->id,
    ];

    // El front manda 115 (15%) pero el backend graba 108 (8%).
    $this->postJson(route('orders.store'), [
        ...differentiatedIvaPayload($branch, [['product' => differentiatedIvaProduct($branch), 'iva' => 4]], '2026-11-03'),
        'total' => 115,
        'payments' => [$contado(1, 15), $contado(20, 100)],
    ])->assertUnprocessable()->assertJsonPath('message', 'El total de la venta se recalculó a 108 (IVA diferenciado): ajuste las formas de pago a ese total.');

    expect(Order::count())->toBe(0)
        ->and($bank->fresh()->balance)->toBe(0.0);
});

test('en feriado, el payload con una sola forma de pago toma el total recalculado', function () {
    $branch = differentiatedIvaBranch();
    Holiday::factory()->create(['starts_at' => '2026-11-01', 'ends_at' => '2026-11-03']);

    $this->postJson(route('orders.store'), [
        ...differentiatedIvaPayload($branch, [['product' => differentiatedIvaProduct($branch), 'iva' => 4]], '2026-11-03'),
        'total' => 115,
    ])->assertSuccessful();

    expect(Order::firstOrFail()->payments()->sole()->amount)->toBe(108.0);
});
