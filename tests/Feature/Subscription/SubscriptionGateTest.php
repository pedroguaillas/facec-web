<?php

use App\Jobs\ProcessVoucherJob;
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
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Branch, 1: Customer}
 */
function gateActingAsCompany(Company $company): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id]);

    return [$branch, $customer];
}

function gateCreditOrderPayload(Branch $branch, Customer $customer): array
{
    $emisionPoint = EmisionPoint::factory()->for($branch)->create();
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return [
        'customer_id' => $customer->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $emisionPoint->id,
        'date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => PaymentTypes::CREDITO,
        'products' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => 0,
            'iva' => 0,
        ]],
        'aditionals' => [],
    ];
}

test('empresa con plan facturador no puede vender a crédito', function () {
    $company = Company::factory()->plan(PlanTypes::FACTURADOR)->create();
    [$branch, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('orders.store'), gateCreditOrderPayload($branch, $customer));

    $response->assertForbidden()->assertJsonPath('error', 'plan_required');
    expect($response->json('message'))->toContain('plan financiero');
});

test('empresa sin ninguna suscripción no puede vender a crédito', function () {
    $company = Company::factory()->withoutSubscription()->create();
    [$branch, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('orders.store'), gateCreditOrderPayload($branch, $customer));

    $response->assertForbidden()->assertJsonPath('error', 'subscription_expired');
});

test('empresa con plan gratuito vigente sí puede vender a crédito', function () {
    $company = Company::factory()->create();
    $company->subscriptions()->delete();
    $company->subscriptions()->create([
        'plan' => PlanTypes::GRATUITO,
        'started_at' => now()->subDays(10),
        'ended_at' => now()->addDays(20),
    ]);

    [$branch, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('orders.store'), gateCreditOrderPayload($branch, $customer));

    $response->assertCreated();
});

test('empresa con plan gratuito vencido no puede vender a crédito', function () {
    $company = Company::factory()->create();
    $company->subscriptions()->delete();
    $company->subscriptions()->create([
        'plan' => PlanTypes::GRATUITO,
        'started_at' => now()->subMonths(2),
        'ended_at' => now()->subMonth(),
    ]);

    [$branch, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('orders.store'), gateCreditOrderPayload($branch, $customer));

    $response->assertForbidden()->assertJsonPath('error', 'subscription_expired');
});

test('empresa con plan financiero sí puede vender a crédito', function () {
    $company = Company::factory()->create(); // financiero por defecto
    [$branch, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('orders.store'), gateCreditOrderPayload($branch, $customer));

    $response->assertCreated();
});

test('empresa con plan facturador no puede registrar saldo inicial de CxC y recibe 403 plan_required', function () {
    $company = Company::factory()->plan(PlanTypes::FACTURADOR)->create();
    [, $customer] = gateActingAsCompany($company);

    $response = test()->postJson(route('customers.account-receivable.opening-balance', $customer), [
        'amount' => 200,
    ]);

    $response->assertForbidden()
        ->assertJsonPath('succes', false)
        ->assertJsonPath('error', 'plan_required')
        ->assertJsonPath('required_plan', PlanTypes::FINANCIERO);
});

test('empresa con plan vencido no puede emitir ni una venta al contado', function () {
    $company = Company::factory()->plan(PlanTypes::FACTURADOR)->create();
    $company->subscriptions()->update(['started_at' => now()->subMonths(2), 'ended_at' => now()->subDay()]);
    [$branch, $customer] = gateActingAsCompany($company);

    $payload = [...gateCreditOrderPayload($branch, $customer), 'payment_type' => PaymentTypes::CONTADO, 'due_date' => null];

    test()->postJson(route('orders.store'), $payload)
        ->assertForbidden()
        ->assertJsonPath('error', 'subscription_expired');
});

test('empresa con plan vencido no puede procesar comprobantes ante el SRI', function () {
    $company = Company::factory()->withoutSubscription()->create(['active_voucher' => true]);
    [$branch] = gateActingAsCompany($company);
    $order = Order::factory()->create(['branch_id' => $branch->id]);

    test()->getJson(route('orders.process', $order))
        ->assertForbidden()
        ->assertJsonPath('error', 'subscription_expired');
});

test('empresa con plan vencido sigue pudiendo consultar sus ventas', function () {
    $company = Company::factory()->withoutSubscription()->create();
    gateActingAsCompany($company);

    test()->getJson(route('orders.index'))->assertOk();
});

test('empresa con plan facturador vigente puede procesar comprobantes ante el SRI', function () {
    Queue::fake();
    $company = Company::factory()->plan(PlanTypes::FACTURADOR)->create(['active_voucher' => true]);
    [$branch] = gateActingAsCompany($company);
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
    ]);

    test()->getJson(route('orders.process', $order))->assertOk();

    Queue::assertPushed(ProcessVoucherJob::class);
});
