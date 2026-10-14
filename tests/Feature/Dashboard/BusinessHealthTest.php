<?php

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Holiday;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopRetentionItem;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\Services\Dashboard\BusinessHealthService;
use App\StaticClasses\AccountsPayableMovementTypes;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00'));

    DB::table('taxes')->insertOrIgnore([
        ['code' => '312', 'conception' => 'Transferencia de bienes', 'porcentage' => 1.75, 'type' => 'renta'],
        ['code' => '1', 'conception' => 'IVA 30%', 'porcentage' => 30, 'type' => 'iva'],
    ]);
});

function healthBranch(array $companyAttributes = [], string $plan = PlanTypes::FINANCIERO): Branch
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->plan($plan)->create(['ruc' => '1790012345001', ...$companyAttributes]);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return $branch;
}

function healthVoucherTypeId(string $slug): int
{
    return VoucherType::where('slug', $slug)->value('id');
}

function healthCreditOrder(Branch $branch, Customer $customer, float $total, string $dueDate, array $attributes = []): Order
{
    return Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'payment_type' => PaymentTypes::CREDITO,
        'total' => $total,
        'paid' => 0,
        'due_date' => $dueDate,
        ...$attributes,
    ]);
}

test('salud del negocio solo con plan financiero o gratuito', function (string $plan, int $status, ?string $error) {
    healthBranch(plan: $plan);

    $response = $this->getJson(route('dashboard.health'))->assertStatus($status);

    if ($error) {
        $response->assertJsonPath('error', $error);
    }
})->with([
    'financiero' => [PlanTypes::FINANCIERO, 200, null],
    'gratuito' => [PlanTypes::GRATUITO, 200, null],
    'facturador' => [PlanTypes::FACTURADOR, 403, 'plan_required'],
]);

test('con plan vencido responde subscription_expired', function () {
    $branch = healthBranch();
    $branch->company->subscriptions()->update(['ended_at' => now()->subDay()]);

    $this->getJson(route('dashboard.health'))->assertForbidden()->assertJsonPath('error', 'subscription_expired');
});

test('flujo de caja y antigüedad de cartera con vencidos, notas de crédito, anulados y saldos iniciales', function () {
    $branch = healthBranch();
    $user = auth()->user();

    BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 1000]);
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);
    CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => 200, 'status' => CashRegisterStatuses::OPEN]);

    $late = Customer::factory()->create(['branch_id' => $branch->id, 'name' => 'Cliente Moroso']);
    $other = Customer::factory()->create(['branch_id' => $branch->id, 'name' => 'Cliente Saldo Inicial']);

    healthCreditOrder($branch, $late, 100, '2026-08-29', ['paid' => 20]);          // 40 días vencido → 80
    $withCreditNote = healthCreditOrder($branch, $late, 300, '2026-10-18');        // semana 2 → 300 - 50 NC
    healthCreditOrder($branch, $late, 500, '2026-12-07');                          // fuera del horizonte de 30 días
    healthCreditOrder($branch, $late, 999, '2026-09-01', ['state' => VoucherStates::CANCELED]);

    AccountReceivableMovement::create([
        'branch_id' => $branch->id, 'customer_id' => $late->id, 'order_id' => $withCreditNote->id,
        'type' => AccountsReceivableMovementTypes::NOTA_CREDITO, 'debe' => 0, 'haber' => 50,
        'previous_balance' => 0, 'new_balance' => 0, 'description' => 'test', 'date' => '2026-10-01',
    ]);
    AccountReceivableMovement::create([
        'branch_id' => $branch->id, 'customer_id' => $other->id, 'order_id' => null,
        'type' => AccountsReceivableMovementTypes::SALDO_INICIAL, 'debe' => 70, 'haber' => 0,
        'previous_balance' => 0, 'new_balance' => 70, 'description' => 'test', 'date' => '2026-01-01',
    ]);

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id, 'payment_type' => PaymentTypes::CREDITO,
        'total' => 200, 'paid' => 0, 'due_date' => '2026-10-13',
    ]);
    ShopRetentionItem::create(['shop_id' => $shop->id, 'code' => 1, 'tax_code' => '312', 'base' => 200, 'porcentage' => 10, 'value' => 20]);
    AccountPayableMovement::create([
        'branch_id' => $branch->id, 'provider_id' => $shop->provider_id, 'shop_id' => null,
        'type' => AccountsPayableMovementTypes::SALDO_INICIAL, 'debe' => 0, 'haber' => 40,
        'previous_balance' => 0, 'new_balance' => 40, 'description' => 'test', 'date' => '2026-01-01',
    ]);

    $response = $this->getJson(route('dashboard.health'))->assertOk();

    $response->assertJsonPath('cashflow.cash', 1200)
        ->assertJsonPath('cashflow.receivable', 400)
        ->assertJsonPath('cashflow.payable', 220)
        ->assertJsonPath('cashflow.projected', 1380)
        ->assertJsonPath('cashflow.weeks.0', ['from' => '2026-10-08', 'to' => '2026-10-14', 'in' => 150, 'out' => 220])
        ->assertJsonPath('cashflow.weeks.1', ['from' => '2026-10-15', 'to' => '2026-10-21', 'in' => 250, 'out' => 0])
        ->assertJsonPath('cashflow.weeks.3.to', '2026-11-07')
        ->assertJsonPath('aging.buckets', ['current' => 750, 'd1_30' => 0, 'd31_60' => 80, 'd61_90' => 0, 'd90_plus' => 0, 'no_due' => 70])
        ->assertJsonPath('aging.counts', ['current' => 2, 'd1_30' => 0, 'd31_60' => 1, 'd61_90' => 0, 'd90_plus' => 0, 'no_due' => 1])
        ->assertJsonPath('aging.to_collect', [
            ['customer_id' => $late->id, 'name' => 'Cliente Moroso', 'amount' => 80, 'oldest_due_date' => '2026-08-29', 'days_overdue' => 40],
            ['customer_id' => $other->id, 'name' => 'Cliente Saldo Inicial', 'amount' => 70, 'oldest_due_date' => null, 'days_overdue' => null],
        ]);
});

test('apartado SRI del régimen general: IVA de ventas, compras y retenciones del mes', function () {
    $branch = healthBranch(['rimpe' => 0]);

    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED, 'date' => '2026-10-02', 'iva15' => 150, 'iva_retention' => 6]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED, 'date' => '2026-10-03', 'iva15' => 15, 'voucher_type_id' => healthVoucherTypeId(VoucherTypes::VENTA_NOTA_CREDITO)]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::SAVED, 'date' => '2026-10-04', 'iva15' => 999]);
    Order::factory()->create(['branch_id' => $branch->id, 'state' => VoucherStates::AUTHORIZED, 'date' => '2026-09-30', 'iva15' => 999]);

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id, 'date' => '2026-10-05', 'iva15' => 45,
        'serie_retencion' => '001-001-000000001', 'date_retention' => '2026-10-05', 'state_retencion' => VoucherStates::AUTHORIZED,
    ]);
    Shop::factory()->create(['branch_id' => $branch->id, 'date' => '2026-10-05', 'iva15' => 999, 'voucher_type_id' => healthVoucherTypeId(VoucherTypes::COMPRA_NOTA_VENTA)]);
    ShopRetentionItem::create(['shop_id' => $shop->id, 'code' => 2, 'tax_code' => '1', 'base' => 45, 'porcentage' => 22.22, 'value' => 10]);
    ShopRetentionItem::create(['shop_id' => $shop->id, 'code' => 1, 'tax_code' => '312', 'base' => 300, 'porcentage' => 1.75, 'value' => 5]);

    $this->getJson(route('dashboard.health'))
        ->assertOk()
        ->assertJsonPath('sri_reserve', [
            'regime' => 'general',
            'frequency' => 'monthly',
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'iva_sales' => 135,
            'iva_purchases' => 45,
            'iva_withheld' => 6,
            'iva_retained' => 10,
            'rent_retained' => 5,
            'estimated' => 94,
            'purchases_count' => 1,
            'due_date' => '2026-11-16',
        ]);
});

test('apartado SRI de RIMPE emprendedor es semestral y negocio popular no aplica', function () {
    healthBranch(['rimpe' => 1]);

    $this->getJson(route('dashboard.health'))
        ->assertOk()
        ->assertJsonPath('sri_reserve.regime', 'rimpe_emprendedor')
        ->assertJsonPath('sri_reserve.frequency', 'semiannual')
        ->assertJsonPath('sri_reserve.period_from', '2026-07-01')
        ->assertJsonPath('sri_reserve.period_to', '2026-12-31')
        ->assertJsonPath('sri_reserve.due_date', '2027-01-18');

    healthBranch(['rimpe' => Company::RIMPE_NEGOCIO_POPULAR, 'ruc' => '1790099999001']);

    $this->getJson(route('dashboard.health'))
        ->assertOk()
        ->assertJsonPath('sri_reserve.regime', 'rimpe_popular')
        ->assertJsonPath('sri_reserve.estimated', null)
        ->assertJsonPath('sri_reserve.due_date', null);
});

test('la fecha de declaración pasa al siguiente día hábil si cae fin de semana o feriado', function () {
    $service = app(BusinessHealthService::class);

    // Dígito 2 → día 12; 2026-12-12 es sábado → lunes 14.
    expect($service->declarationDueDate('1790012320001', CarbonImmutable::parse('2026-11-30'))->toDateString())->toBe('2026-12-14');

    Holiday::factory()->create(['starts_at' => '2026-12-14', 'ends_at' => '2026-12-14']);

    expect($service->declarationDueDate('1790012320001', CarbonImmutable::parse('2026-11-30'))->toDateString())->toBe('2026-12-15');
});
