<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use App\Models\Product\Warehouse;
use App\Models\Treasury\BankAccount;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;

/*
 * Helpers compartidos por los tests de tests/Feature/Inventory (no es un
 * archivo de tests: Pest solo carga *Test.php).
 */

/**
 * @return array{0: Branch, 1: Company, 2: Warehouse}
 */
function voucherInventoryCompany(array $companyAttributes = []): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['inventory' => true, ...$companyAttributes]);
    $branch = Branch::factory()->for($company)->create();
    $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return [$branch, $company, $warehouse];
}

function stockProduct(Branch $branch): Product
{
    return Product::factory()->create(['branch_id' => $branch->id, 'type_product' => Product::TYPE_PRODUCT, 'iva' => 0, 'stock' => null]);
}

function orderWithItems(Branch $branch, string $voucherSlug, Product $product, float $quantity, float $price = 10): Order
{
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', $voucherSlug)->value('id'),
        'date' => now()->toDateString(),
        'state' => VoucherStates::SAVED,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'price' => $price,
        'discount' => 0,
        'iva' => 0,
    ]);

    return $order;
}

/**
 * @return array<string, mixed>
 */
function invoicePayload(Branch $branch, Product $product, float $quantity): array
{
    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'total' => $quantity * 10,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => now()->format('Y-m-d'),
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0])->id,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => 10,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ];
}

/**
 * Modelo de moto con plantilla: Marca (modelo, SRI), Chasis (unidad, código, SRI), Motor (unidad).
 */
function saleMotoModel(Branch $branch): Product
{
    $template = ProductTemplate::factory()->for($branch)->create(['name' => 'Moto', 'code_prefix' => 'M']);
    ProductTemplateField::factory()->for($template, 'template')->create([
        'key' => 'marca', 'label' => 'Marca', 'level' => 'modelo', 'in_sri' => true, 'sort' => 1,
    ]);
    $chasis = ProductTemplateField::factory()->for($template, 'template')->create([
        'key' => 'chasis', 'label' => 'Chasis', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => true, 'sort' => 2,
    ]);
    ProductTemplateField::factory()->for($template, 'template')->create([
        'key' => 'motor', 'label' => 'Motor', 'level' => 'unidad', 'required' => true, 'unique' => true, 'in_sri' => false, 'sort' => 3,
    ]);
    $template->update(['code_field_id' => $chasis->id]);

    $model = Product::factory()->create([
        'branch_id' => $branch->id,
        'type_product' => Product::TYPE_PRODUCT,
        'name' => 'MOTO M1 R1 2026',
        'price1' => 2500,
        'iva' => 0,
        'stock' => null,
        'product_template_id' => $template->id,
    ]);
    $model->details()->create(['product_template_field_id' => $template->fields()->where('key', 'marca')->value('id'), 'value' => 'M1']);

    return $model;
}

function saleMotoUnit(Product $model, string $chasis, string $motor): Product
{
    return Product::findOrFail(test()->postJson(route('products.units.store', $model), [
        'details' => ['chasis' => $chasis, 'motor' => $motor],
    ])->assertCreated()->json('id'));
}

/**
 * @param  array<int, Product>  $products
 */
function unitSalePayload(Branch $branch, array $products, string $voucherSlug = VoucherTypes::VENTA_FACTURA): array
{
    return [
        ...invoicePayload($branch, $products[0], 1),
        'voucher_type_id' => VoucherType::where('slug', $voucherSlug)->value('id'),
        'total' => 10 * count($products),
        'products' => array_map(fn (Product $product) => [
            'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'iva' => 0,
        ], $products),
    ];
}
