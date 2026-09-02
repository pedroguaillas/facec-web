<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Product\SriCategory;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;

function plateActingAsCompany(): Branch
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

function plateOrderPayload(Branch $branch, Product $product, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create(['point' => 1]);
    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    return array_merge([
        'customer_id' => $customer->id,
        'total' => 10,
        'voucher_type' => 1,
        'point_id' => $emisionPoint->id,
        'serie' => '001001',
        'date' => now()->format('Y-m-d'),
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

test('plate es obligatoria si la venta incluye un servicio de transporte', function () {
    $branch = plateActingAsCompany();
    SriCategory::create(['code' => 'H492001', 'type' => 'transporte', 'description' => 'Operadora al cliente']);

    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_SERVICE,
        'aux_cod' => 'H492001',
        'iva' => 2,
    ]);

    $response = $this->postJson(route('orders.store'), plateOrderPayload($branch, $product));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('plate');
});

test('plate NO es obligatoria si la venta no incluye servicio de transporte', function () {
    $branch = plateActingAsCompany();

    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_PRODUCT,
        'iva' => 2,
    ]);

    $response = $this->postJson(route('orders.store'), plateOrderPayload($branch, $product));

    $response->assertCreated();
});

test('plate se guarda en la orden cuando la venta incluye servicio de transporte', function () {
    $branch = plateActingAsCompany();
    SriCategory::create(['code' => 'H492001', 'type' => 'transporte', 'description' => 'Operadora al cliente']);

    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_SERVICE,
        'aux_cod' => 'H492001',
        'iva' => 2,
    ]);

    $response = $this->postJson(route('orders.store'), plateOrderPayload($branch, $product, ['plate' => 'PCM4567']));

    $response->assertCreated();
    expect(Order::first()->plate)->toBe('PCM4567');
});
