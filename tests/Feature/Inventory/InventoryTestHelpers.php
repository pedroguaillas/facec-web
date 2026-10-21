<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
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
