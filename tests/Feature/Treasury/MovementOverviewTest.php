<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Treasury\Bank;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\MovementCategories;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;

/**
 * @return array{0: Branch, 1: User, 2: BankAccount}
 */
function movementOverviewSetup(string $plan = PlanTypes::FINANCIERO): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->plan($plan)->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    $bank = Bank::factory()->create(['branch_id' => $branch->id, 'name' => 'Pichincha']);
    $account = BankAccount::factory()->for($bank)->create([
        'branch_id' => $branch->id,
        'account_number' => '2200123456',
        'balance' => 1500,
    ]);

    return [$branch, $user, $account];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function overviewMovement(Branch $branch, User $user, BankAccount $account, array $attributes): Movement
{
    return Movement::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'status' => MovementStatuses::COMPLETED,
        'date' => '2026-10-05',
        ...$attributes,
    ]);
}

test('filtra movimientos por fechas, direccion, tipo y categoria', function () {
    [$branch, $user, $account] = movementOverviewSetup();

    $sale = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
        'category' => MovementCategories::SALES,
    ]);
    $rent = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'category' => MovementCategories::RENT,
    ]);
    $unclassified = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'category' => null,
    ]);
    $transfer = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::TRANSFER,
    ]);
    overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
        'date' => '2026-09-30',
    ]);

    $ids = fn (array $query) => collect($this->getJson(route('movements.index', $query))->assertOk()->json('data'))->pluck('id')->all();

    $period = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    expect($ids($period))->toBe([$transfer->id, $unclassified->id, $rent->id, $sale->id])
        ->and($ids([...$period, 'direction' => 'in']))->toBe([$sale->id])
        ->and($ids([...$period, 'direction' => 'out']))->toBe([$unclassified->id, $rent->id])
        ->and($ids([...$period, 'transaction_type' => 'OTRO,TRANSFERENCIA']))->toBe([$transfer->id, $unclassified->id, $rent->id])
        ->and($ids([...$period, 'category' => MovementCategories::RENT]))->toBe([$rent->id])
        ->and($ids([...$period, 'category' => MovementCategories::UNCLASSIFIED]))->toBe([$transfer->id, $unclassified->id]);

    $this->getJson(route('movements.index', ['category' => MovementCategories::RENT]))
        ->assertJsonPath('data.0.atts.category', MovementCategories::RENT)
        ->assertJsonPath('data.0.origin', null);
});

test('el abono de una venta se categoriza y enlaza a la factura', function () {
    [$branch, , $account] = movementOverviewSetup();

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'payment_type' => PaymentTypes::CREDITO,
        'total' => 100,
        'paid' => 0,
        'serie' => '001-001-000000123',
    ]);

    $this->postJson(route('orders.payments.store', $order), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
    ])->assertCreated();

    $movement = Movement::firstOrFail();

    expect($movement->category)->toBe(MovementCategories::SALES)
        ->and($movement->origin_type)->toBe(MovementPartyRegistry::ORDER)
        ->and($movement->origin_id)->toBe($order->id);

    $this->getJson(route('movements.index'))
        ->assertJsonPath('data.0.origin', ['type' => 'order', 'id' => $order->id, 'number' => '001-001-000000123']);
});

test('los movimientos internos no llevan categoria', function () {
    [$branch, $user, $account] = movementOverviewSetup();
    $destination = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

    $this->postJson(route('movements.transfer'), [
        'origin_bank_account_id' => $account->id,
        'destination_bank_account_id' => $destination->id,
        'amount' => 100,
    ])->assertCreated()->assertJsonPath('atts.category', null);
});

test('movimiento suelto acepta categoria valida para su tipo', function () {
    [, , $account] = movementOverviewSetup();

    $payload = [
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'type' => MovementTypes::EXPENSE,
        'amount' => 50,
    ];

    $this->postJson(route('movements.other'), [...$payload, 'category' => MovementCategories::SALARIES])
        ->assertCreated()
        ->assertJsonPath('atts.category', MovementCategories::SALARIES);

    $this->postJson(route('movements.other'), [...$payload, 'category' => MovementCategories::OWNER_CONTRIBUTION])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category' => 'La categoría no corresponde al tipo de movimiento.']);
});

test('clasifica despues solo movimientos manuales', function () {
    [$branch, $user, $account] = movementOverviewSetup();

    $other = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::EXPENSE,
        'transaction_type' => MovementTransactionTypes::OTHER,
        'category' => null,
    ]);
    $sale = overviewMovement($branch, $user, $account, [
        'type' => MovementTypes::INCOME,
        'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
        'category' => MovementCategories::SALES,
    ]);

    $this->patchJson(route('movements.category', $other), ['category' => MovementCategories::OWNER_WITHDRAWAL])
        ->assertOk()
        ->assertJsonPath('atts.category', MovementCategories::OWNER_WITHDRAWAL);

    $this->patchJson(route('movements.category', $other), ['category' => MovementCategories::SALES])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category');

    $this->patchJson(route('movements.category', $sale), ['category' => MovementCategories::OTHER_INCOME])
        ->assertUnprocessable()
        ->assertJsonPath('succes', false);

    expect($sale->fresh()->category)->toBe(MovementCategories::SALES);
});

test('resumen de movimientos separa negocio, financiamiento e internos', function () {
    [$branch, $user, $account] = movementOverviewSetup();

    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'name' => 'Caja general']);
    CashRegisterSession::factory()->create([
        'branch_id' => $branch->id,
        'cash_register_id' => $cashRegister->id,
        'opened_by' => $user->id,
        'current_balance' => 120,
        'status' => CashRegisterStatuses::OPEN,
    ]);

    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::SALE_COLLECTION, 'category' => MovementCategories::SALES, 'amount' => 1000]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::SALE_COLLECTION, 'category' => MovementCategories::SALES, 'amount' => 234.5]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::EXPENSE, 'transaction_type' => MovementTransactionTypes::PURCHASE_PAYMENT, 'category' => MovementCategories::PURCHASES, 'amount' => 500]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::EXPENSE, 'transaction_type' => MovementTransactionTypes::OTHER, 'category' => MovementCategories::OWNER_WITHDRAWAL, 'amount' => 200]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::EXPENSE, 'transaction_type' => MovementTransactionTypes::OTHER, 'category' => null, 'amount' => 280]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::OTHER, 'category' => MovementCategories::LOAN_RECEIVED, 'amount' => 100]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::EXPENSE, 'transaction_type' => MovementTransactionTypes::DEPOSIT, 'amount' => 300]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::OPENING_BALANCE, 'amount' => 5000]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::OTHER, 'status' => MovementStatuses::PENDING, 'amount' => 75]);
    overviewMovement($branch, $user, $account, ['type' => MovementTypes::INCOME, 'transaction_type' => MovementTransactionTypes::SALE_COLLECTION, 'date' => '2026-11-01', 'amount' => 999]);

    $response = $this->getJson(route('movements.summary', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))->assertOk();

    expect($response->json())->toMatchArray([
        'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
        'income' => 1234.5,
        'expense' => 780,
        'net' => 454.5,
        'financing' => [
            'owner_contributions' => 0,
            'owner_withdrawals' => 200,
            'loans_received' => 100,
            'loan_payments' => 0,
            'net' => -100,
        ],
        'cash_change' => 354.5,
        'internal' => 300,
        'owner_withdrawals' => 200,
        'pending' => ['income' => 75, 'expense' => 0],
        'by_category' => [
            ['category' => MovementCategories::SALES, 'type' => MovementTypes::INCOME, 'group' => MovementCategories::GROUP_BUSINESS, 'amount' => 1234.5, 'count' => 2],
            ['category' => MovementCategories::PURCHASES, 'type' => MovementTypes::EXPENSE, 'group' => MovementCategories::GROUP_BUSINESS, 'amount' => 500, 'count' => 1],
            ['category' => MovementCategories::UNCLASSIFIED, 'type' => MovementTypes::EXPENSE, 'group' => MovementCategories::GROUP_BUSINESS, 'amount' => 280, 'count' => 1],
            ['category' => MovementCategories::OWNER_WITHDRAWAL, 'type' => MovementTypes::EXPENSE, 'group' => MovementCategories::GROUP_FINANCING, 'amount' => 200, 'count' => 1],
            ['category' => MovementCategories::LOAN_RECEIVED, 'type' => MovementTypes::INCOME, 'group' => MovementCategories::GROUP_FINANCING, 'amount' => 100, 'count' => 1],
        ],
        'accounts' => [
            ['type' => MovementPartyRegistry::BANK_ACCOUNT, 'id' => $account->id, 'name' => 'Pichincha 2200123456', 'balance' => 1500],
            ['type' => 'cash_register', 'id' => $cashRegister->id, 'name' => 'Caja general', 'balance' => 120],
        ],
    ]);
});

test('resumen de movimientos requiere plan financiero', function (string $plan, bool $expired, string $error) {
    movementOverviewSetup($plan);

    if ($expired) {
        Company::first()->subscriptions()->delete();
    }

    $this->getJson(route('movements.summary'))
        ->assertForbidden()
        ->assertJsonPath('error', $error);
})->with([
    'facturador' => [PlanTypes::FACTURADOR, false, 'plan_required'],
    'vencido' => [PlanTypes::FINANCIERO, true, 'subscription_expired'],
]);

test('catalogo de categorias con tipo y grupo', function () {
    movementOverviewSetup();

    $catalog = collect($this->getJson(route('movements.categories'))->assertOk()->json());

    expect($catalog)->toHaveCount(16)
        ->and($catalog->firstWhere('category', MovementCategories::SOCIAL_SECURITY))
        ->toBe(['category' => 'IESS', 'type' => MovementTypes::EXPENSE, 'group' => MovementCategories::GROUP_BUSINESS])
        ->and($catalog->firstWhere('category', MovementCategories::LOAN_PAYMENT))
        ->toBe(['category' => 'PAGO_PRESTAMO', 'type' => MovementTypes::EXPENSE, 'group' => MovementCategories::GROUP_FINANCING])
        ->and($catalog->pluck('category')->unique())->toHaveCount(16);
});
