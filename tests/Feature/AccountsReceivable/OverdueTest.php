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
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

/**
 * @return array{0: Branch, 1: Customer}
 */
function overdueActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    return [$branch, $customer];
}

function overdueOrderPayload(Branch $branch, Customer $customer, float $total, string $dueDate, array $overrides = []): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return array_merge([
        'customer_id' => $customer->id,
        'total' => $total,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->subDays(40)->format('Y-m-d'),
        'due_date' => $dueDate,
        'payment_type' => PaymentTypes::CREDITO,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ], $overrides);
}

test('factura vencida con saldo pendiente aparece en el reporte, agrupada por cliente', function () {
    [$branch, $customer] = overdueActingAsCompany();

    $vencida = overdueOrderPayload($branch, $customer, 100, now()->subDays(10)->format('Y-m-d'));
    test()->postJson(route('orders.store'), $vencida)->assertCreated();

    $response = test()->getJson(route('customers.overdue'));

    $response->assertOk();
    expect((float) $response->json('total_overdue'))->toBe(100.0)
        ->and($response->json('parties'))->toHaveCount(1)
        ->and($response->json('parties.0.customer_id'))->toBe($customer->id)
        ->and((float) $response->json('parties.0.total_overdue'))->toBe(100.0)
        ->and($response->json('parties.0.documents'))->toHaveCount(1)
        ->and($response->json('parties.0.documents.0.days_overdue'))->toBe(10);
});

test('factura a crédito sin vencer no aparece en el reporte', function () {
    [$branch, $customer] = overdueActingAsCompany();

    $vigente = overdueOrderPayload($branch, $customer, 100, now()->addDays(10)->format('Y-m-d'));
    test()->postJson(route('orders.store'), $vigente)->assertCreated();

    $response = test()->getJson(route('customers.overdue'));

    $response->assertOk();
    expect($response->json('parties'))->toHaveCount(0)
        ->and((float) $response->json('total_overdue'))->toBe(0.0);
});

test('factura vencida pero ya pagada no aparece en el reporte', function () {
    [$branch, $customer] = overdueActingAsCompany();

    $vencida = overdueOrderPayload($branch, $customer, 100, now()->subDays(5)->format('Y-m-d'));
    $response = test()->postJson(route('orders.store'), $vencida);
    $response->assertCreated();

    $order = Order::first();
    $order->update(['paid' => $order->total]);

    $response = test()->getJson(route('customers.overdue'));

    $response->assertOk();
    expect($response->json('parties'))->toHaveCount(0);
});
