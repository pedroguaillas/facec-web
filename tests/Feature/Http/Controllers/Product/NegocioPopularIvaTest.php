<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

function negocioPopularActingAsCompany(int $rimpe = Company::RIMPE_NEGOCIO_POPULAR): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['rimpe' => $rimpe]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 0, 'percentage' => 0, 'state' => 'active'],
        ['code' => 4, 'percentage' => 15, 'state' => 'active'],
    ]);

    return $branch;
}

function negocioPopularOrderPayload(Branch $branch, int $iva): array
{
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'total' => 10,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => now()->format('Y-m-d'),
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'iva' => $iva,
        ]],
        'aditionals' => [],
    ];
}

test('negocio popular solo recibe IVA 0% al crear productos, con percentage numérico', function () {
    negocioPopularActingAsCompany();

    $this->getJson(route('products.create'))
        ->assertOk()
        ->assertJsonPath('ivaTaxes', [['value' => 0, 'label' => '0%', 'percentage' => 0]]);
});

test('una empresa que no es negocio popular recibe todas las tarifas con percentage', function () {
    negocioPopularActingAsCompany(rimpe: 0);

    $this->getJson(route('products.create'))
        ->assertOk()
        ->assertJsonFragment(['value' => 4, 'label' => '15%', 'percentage' => 15]);
});

test('negocio popular no puede guardar un producto con IVA distinto de 0', function () {
    $branch = negocioPopularActingAsCompany();
    $payload = ['code' => 'P001', 'type_product' => 2, 'name' => 'Servicio', 'price1' => 10];

    $this->postJson(route('products.store'), [...$payload, 'iva' => 4])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('iva');

    $this->postJson(route('products.store'), [...$payload, 'iva' => 0])
        ->assertCreated();

    $product = Product::where('branch_id', $branch->id)->firstOrFail();

    $this->putJson(route('products.update', $product), [...$payload, 'iva' => 4])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('iva');
});

test('negocio popular solo recibe IVA 0% al crear ventas', function () {
    negocioPopularActingAsCompany();

    $this->getJson(route('orders.create'))
        ->assertOk()
        ->assertJsonCount(1, 'ivaTaxes')
        ->assertJsonPath('ivaTaxes.0.code', 0);
});

test('negocio popular no puede vender con IVA distinto de 0', function (int $iva) {
    $branch = negocioPopularActingAsCompany();

    $this->postJson(route('orders.store'), negocioPopularOrderPayload($branch, $iva))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('products.0.iva');
})->with(['15%' => 4, 'no objeto' => 6, 'exento' => 7]);

test('una empresa que no es negocio popular puede vender con IVA 15%', function () {
    $branch = negocioPopularActingAsCompany(rimpe: 0);

    $this->postJson(route('orders.store'), negocioPopularOrderPayload($branch, 4))
        ->assertJsonMissingValidationErrors('products.0.iva');
});
