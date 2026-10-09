<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherTypes;

function cashWithoutSourceBranch(string $plan): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->plan($plan)->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $branch;
}

/**
 * @return array<string, mixed>
 */
function cashWithoutSourceOrderPayload(Branch $branch): array
{
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'total' => 10,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => now()->format('Y-m-d'),
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
        'payment_type' => PaymentTypes::CONTADO,
        'without_source' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function cashWithoutSourceShopPayload(Branch $branch): array
{
    return [
        'date' => now()->format('Y-m-d'),
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc'])->id,
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'serie' => '001-001-000123',
        'total' => 10,
        'payment_type' => PaymentTypes::CONTADO,
        'without_source' => true,
    ];
}

test('con plan facturador la venta al contado no pide caja/banco ni genera movimiento', function () {
    $branch = cashWithoutSourceBranch(PlanTypes::FACTURADOR);

    $this->postJson(route('orders.store'), cashWithoutSourceOrderPayload($branch))->assertSuccessful();

    expect(Order::count())->toBe(1)
        ->and(Order::first()->movement_id)->toBeNull()
        ->and(Movement::count())->toBe(0);
});

test('con plan facturador la compra al contado no pide caja/banco ni genera movimiento', function () {
    $branch = cashWithoutSourceBranch(PlanTypes::FACTURADOR);

    $this->postJson(route('shops.store'), cashWithoutSourceShopPayload($branch))->assertCreated();

    expect(Shop::count())->toBe(1)
        ->and(Movement::count())->toBe(0);
});

test('con plan facturador se ignora la caja/banco aunque venga', function () {
    $branch = cashWithoutSourceBranch(PlanTypes::FACTURADOR);
    $user = auth()->user();
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    $session = CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => 100]);

    $this->postJson(route('orders.store'), [
        ...cashWithoutSourceOrderPayload($branch),
        'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
        'source_id' => $session->id,
    ])->assertSuccessful();

    expect(Movement::count())->toBe(0)
        ->and($session->fresh()->current_balance)->toBe(100.0);
});

test('con plan financiero o gratuito la venta y la compra al contado siguen exigiendo caja/banco', function (string $plan) {
    $branch = cashWithoutSourceBranch($plan);

    $this->postJson(route('orders.store'), cashWithoutSourceOrderPayload($branch))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_type', 'source_id']);

    $this->postJson(route('shops.store'), cashWithoutSourceShopPayload($branch))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source_type', 'source_id']);
})->with([PlanTypes::FINANCIERO, PlanTypes::GRATUITO]);
