<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;

function pdfActingAsCompany(): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create(['store' => 1]);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 2, 'percentage' => 12, 'state' => 'active'],
    ]);

    return $branch;
}

test('el PDF de una factura renderiza completo (título, info y cabecera de empresa) sin variables indefinidas', function () {
    $branch = pdfActingAsCompany();
    $facturaId = VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id');

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $facturaId,
        'serie' => '001-001-000000001',
    ]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $order->orderitems()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'ice' => 0, 'iva' => 2,
    ]);

    $response = $this->get(route('orders.pdf', $order));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});

test('el PDF de una nota de credito renderiza completo sin variables indefinidas', function () {
    $branch = pdfActingAsCompany();
    $notaCreditoId = VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id');

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $notaCreditoId,
        'serie' => '001-001-000000002',
        'date_order' => now()->format('Y-m-d'),
    ]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $order->orderitems()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'ice' => 0, 'iva' => 2,
    ]);

    $response = $this->get(route('orders.pdf', $order));

    $response->assertOk();
});

test('el PDF de una nota de venta renderiza completo, reusando la referencia de factura sin campos electronicos', function () {
    $branch = pdfActingAsCompany();
    $notaVentaId = VoucherType::where('slug', VoucherTypes::VENTA_NOTA_VENTA)->value('id');

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $notaVentaId,
        'serie' => '001-001-000000003',
    ]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $order->orderitems()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'ice' => 0, 'iva' => 2,
    ]);

    $response = $this->get(route('orders.pdf', $order));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});

test('el PDF de una proforma renderiza completo con su diseño propio', function () {
    $branch = pdfActingAsCompany();
    $proformaId = VoucherType::where('slug', VoucherTypes::VENTA_PROFORMA)->value('id');

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => $proformaId,
        'serie' => '001-001-000000004',
        'expiration_days' => 15,
    ]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 2]);
    $order->orderitems()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'ice' => 0, 'iva' => 2,
    ]);

    $response = $this->get(route('orders.pdf', $order));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});
