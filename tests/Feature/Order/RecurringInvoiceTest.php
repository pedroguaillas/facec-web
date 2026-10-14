<?php

use App\Jobs\EmitRecurringInvoiceJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\RecurringInvoice;
use App\Models\Order\RecurringInvoiceRun;
use App\Models\Product\Product;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\Services\Order\OrderLifecycleService;
use App\Services\Order\OrderPdfService;
use App\Services\Order\OrderUpdateService;
use App\Services\RecurringInvoice\RecurringSchedule;
use App\Services\Treasury\CashRegisterOpeningService;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\PlanTypes;
use App\StaticClasses\RecurringFrequencies;
use App\StaticClasses\RecurringInvoiceStatuses;
use App\StaticClasses\RecurringRunStatuses;
use App\StaticClasses\VoucherTypes;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\MethodOfPaymentSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 06:00'));
    $this->seed(MethodOfPaymentSeeder::class);

    DB::table('iva_taxes')->insertOrIgnore([
        ['code' => 0, 'percentage' => 0, 'state' => 'active'],
        ['code' => 4, 'percentage' => 15, 'state' => 'active'],
    ]);
});

/**
 * @return array{0: Branch, 1: User, 2: Customer, 3: EmisionPoint, 4: Product}
 */
function recurringSetup(string $plan = PlanTypes::FINANCIERO, array $companyAttributes = []): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->plan($plan)->create($companyAttributes);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);

    test()->actingAs($user);

    return [
        $branch,
        $user,
        Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '0102030405', 'type_identification' => 'cédula']),
        EmisionPoint::factory()->for($branch)->create(),
        Product::factory()->create(['branch_id' => $branch->id, 'iva' => 4]),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recurringPayload(Customer $customer, EmisionPoint $point, Product $product, array $overrides = []): array
{
    return [
        'name' => 'Mantenimiento web',
        'customer_id' => $customer->id,
        'emision_point_id' => $point->id,
        'frequency' => RecurringFrequencies::MONTHLY,
        'start_at' => '2026-10-09 06:00',
        'payment_type' => PaymentTypes::CREDITO,
        'due_days' => 15,
        'pay_method' => 20,
        'auto_send' => false,
        'description' => 'Servicio {periodo}',
        'aditionals' => [['name' => 'Periodo', 'description' => '{mes} {año}']],
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4]],
        ...$overrides,
    ];
}

/**
 * Como en el worker/scheduler: sin usuario autenticado en ningún guard.
 */
function recurringLogout(): void
{
    app('auth')->forgetGuards();
}

/**
 * Crea la plantilla vía API y simula la corrida diaria (sin usuario logueado, como en el scheduler).
 */
function recurringCreate(array $payload): RecurringInvoice
{
    $id = test()->postJson(route('recurring-invoices.store'), $payload)->assertCreated()->json('id');

    return RecurringInvoice::findOrFail($id);
}

function recurringRunDaily(): void
{
    recurringLogout();
    test()->artisan('invoices:recurring')->assertSuccessful();
}

test('el calendario no desborda meses y cuenta siempre desde el inicio', function () {
    $schedule = new RecurringSchedule;
    $start = CarbonImmutable::parse('2026-01-31');

    expect($schedule->occurrence($start, RecurringFrequencies::MONTHLY, 1)->toDateString())->toBe('2026-02-28')
        ->and($schedule->occurrence($start, RecurringFrequencies::MONTHLY, 2)->toDateString())->toBe('2026-03-31')
        ->and($schedule->occurrence($start, RecurringFrequencies::QUARTERLY, 1)->toDateString())->toBe('2026-04-30')
        ->and($schedule->occurrence($start, RecurringFrequencies::WEEKLY, 2)->toDateString())->toBe('2026-02-14')
        ->and($schedule->firstOnOrAfter($start, RecurringFrequencies::MONTHLY, CarbonImmutable::parse('2026-03-01'))->toDateString())->toBe('2026-03-31')
        ->and($schedule->lastOnOrBefore($start, RecurringFrequencies::MONTHLY, CarbonImmutable::parse('2026-03-30'))->toDateString())->toBe('2026-02-28');
});

test('la plantilla arranca en la primera fecha desde hoy, sin emitir periodos pasados', function () {
    [, , $customer, $point, $product] = recurringSetup();

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product, ['start_at' => '2026-08-15 08:30']))
        ->assertCreated()
        ->assertJsonPath('atts.next_run_at', '2026-10-15 08:30')
        ->assertJsonPath('atts.status', RecurringInvoiceStatuses::ACTIVE)
        ->assertJsonPath('items.0.price', 100);
});

test('valida consumidor final, credito sin plan financiero e IVA de negocio popular', function () {
    [$branch, , $customer, $point, $product] = recurringSetup(PlanTypes::FACTURADOR, ['rimpe' => Company::RIMPE_NEGOCIO_POPULAR]);
    $finalConsumer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '9999999999999']);

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.iva']);

    $zeroIva = ['products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'iva' => 0]]];

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product, $zeroIva))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_type' => 'La venta a crédito requiere el plan financiero.']);

    $this->postJson(route('recurring-invoices.store'), recurringPayload($finalConsumer, $point, $product, [...$zeroIva, 'payment_type' => PaymentTypes::CONTADO]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id' => 'Una factura recurrente no puede ser a consumidor final.']);

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product, [...$zeroIva, 'payment_type' => PaymentTypes::CONTADO]))
        ->assertCreated();
});

test('la corrida diaria emite la factura del periodo una sola vez', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product));

    recurringRunDaily();
    recurringRunDaily();

    $order = Order::withoutGlobalScope('branch')->sole();
    $recurring->refresh();

    expect(Carbon::parse($order->date)->toDateString())->toBe('2026-10-09')
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->payment_type)->toBe(PaymentTypes::CREDITO)
        ->and(Carbon::parse($order->due_date)->toDateString())->toBe('2026-10-24')
        ->and($order->description)->toBe('Servicio octubre 2026')
        ->and($order->total)->toBe(115.0)
        ->and($order->orderaditionals()->where('name', 'Periodo')->value('description'))->toBe('octubre 2026')
        ->and($recurring->emitted_count)->toBe(1)
        ->and($recurring->next_run_at->format('Y-m-d H:i'))->toBe('2026-11-09 06:00')
        ->and($recurring->last_error)->toBeNull()
        ->and(RecurringInvoiceRun::sole()->only(['status', 'order_id']))->toBe(['status' => RecurringRunStatuses::ISSUED, 'order_id' => $order->id])
        ->and(Auth::user())->toBeNull();
});

test('si la corrida no paso varios meses emite solo el ultimo periodo con fecha de hoy', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product, ['start_at' => '2026-07-05 06:00']));
    $recurring->update(['next_run_at' => '2026-07-05 06:00']);

    recurringRunDaily();

    expect(Order::withoutGlobalScope('branch')->count())->toBe(1)
        ->and(Carbon::parse(Order::withoutGlobalScope('branch')->sole()->date)->toDateString())->toBe('2026-10-09')
        ->and(RecurringInvoiceRun::sole()->period_date->toDateString())->toBe('2026-10-05')
        ->and($recurring->fresh()->next_run_at->format('Y-m-d H:i'))->toBe('2026-11-05 06:00');
});

test('un fallo queda en el historial y se reintenta al dia siguiente', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product));
    $company = Company::sole();
    $company->subscriptions()->delete();

    recurringRunDaily();

    $run = RecurringInvoiceRun::sole();
    expect($run->status)->toBe(RecurringRunStatuses::FAILED)
        ->and($run->attempts)->toBe(1)
        ->and($recurring->fresh()->last_error)->not->toBeNull()
        ->and($recurring->fresh()->next_run_at->format('Y-m-d H:i'))->toBe('2026-10-09 06:00')
        ->and(Order::withoutGlobalScope('branch')->count())->toBe(0);

    $company->subscriptions()->create(['plan' => PlanTypes::FINANCIERO, 'started_at' => now()->subDay(), 'ended_at' => now()->addYear(), 'amount' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 06:00'));

    recurringRunDaily();

    expect($run->fresh()->only(['status', 'attempts']))->toBe(['status' => RecurringRunStatuses::ISSUED, 'attempts' => 2])
        ->and(Carbon::parse(Order::withoutGlobalScope('branch')->sole()->date)->toDateString())->toBe('2026-10-10')
        ->and($recurring->fresh()->last_error)->toBeNull()
        ->and($recurring->fresh()->next_run_at->format('Y-m-d H:i'))->toBe('2026-11-09 06:00');
});

test('contado con plan financiero cobra en la sesion abierta de la caja', function () {
    [$branch, $user, $customer, $point, $product] = recurringSetup();
    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'type' => CashRegisterTypes::GENERAL]);

    $recurring = recurringCreate(recurringPayload($customer, $point, $product, [
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::CASH_REGISTER,
        'source_id' => $cashRegister->id,
    ]));

    recurringRunDaily();

    expect(RecurringInvoiceRun::sole()->message)->toBe("La caja \"{$cashRegister->name}\" está cerrada: ábrela o cambia la cuenta de cobro.");

    $session = app(CashRegisterOpeningService::class)->open($cashRegister, 0, $user);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 07:00'));
    recurringRunDaily();

    $order = Order::withoutGlobalScope('branch')->sole();
    $movement = Movement::withoutGlobalScope('branch')->sole();

    expect($movement->source_id)->toBe($session->id)
        ->and($movement->amount)->toBe(115.0)
        ->and($movement->origin_id)->toBe($order->id)
        ->and($recurring->fresh()->emitted_count)->toBe(1);
});

test('emitir ahora adelanta el proximo periodo y finaliza al llegar al limite', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product, ['start_at' => '2026-10-20 09:00', 'max_occurrences' => 2]));

    $this->postJson(route('recurring-invoices.emit', $recurring))
        ->assertCreated()
        ->assertJsonPath('recurring_invoice.atts.next_run_at', '2026-11-20 09:00');

    $this->postJson(route('recurring-invoices.emit', $recurring))
        ->assertCreated()
        ->assertJsonPath('recurring_invoice.atts.status', RecurringInvoiceStatuses::FINISHED)
        ->assertJsonPath('recurring_invoice.atts.next_run_at', null);

    $this->postJson(route('recurring-invoices.emit', $recurring))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La factura recurrente no está activa.');

    expect(RecurringInvoiceRun::pluck('period_date')->map->toDateString()->all())->toBe(['2026-10-20', '2026-11-20'])
        ->and(Order::count())->toBe(2);

    $this->getJson(route('recurring-invoices.edit', $recurring))
        ->assertOk()
        ->assertJsonCount(2, 'runs')
        ->assertJsonPath('runs.0.period_date', '2026-11-20');

    $this->deleteJson(route('recurring-invoices.destroy', $recurring))->assertUnprocessable();
});

test('pausar detiene la emision y reanudar no emite lo atrasado', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product));

    $this->postJson(route('recurring-invoices.pause', $recurring))->assertOk()->assertJsonPath('atts.status', RecurringInvoiceStatuses::PAUSED);
    recurringRunDaily();
    expect(Order::withoutGlobalScope('branch')->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00'));
    $this->actingAs($recurring->user);

    $this->postJson(route('recurring-invoices.resume', $recurring))
        ->assertOk()
        ->assertJsonPath('atts.status', RecurringInvoiceStatuses::ACTIVE)
        ->assertJsonPath('atts.next_run_at', '2026-12-09 06:00');

    $this->deleteJson(route('recurring-invoices.destroy', $recurring))->assertOk();
    expect(RecurringInvoice::count())->toBe(0);
});

test('el job no deja un usuario autenticado en el worker', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product));
    recurringLogout();

    EmitRecurringInvoiceJob::dispatchSync($recurring->id);

    expect(Auth::user())->toBeNull()
        ->and(Order::withoutGlobalScope('branch')->count())->toBe(1);
});

test('cada periodo factura el mismo producto con codigo y descripcion del mes', function () {
    [$branch, , $customer, $point, $product] = recurringSetup();
    $product->update(['code' => 'AC', 'name' => 'Arriendo']);

    $recurring = recurringCreate(recurringPayload($customer, $point, $product, [
        'start_at' => '2026-09-09 06:00',
        'products' => [[
            'product_id' => $product->id,
            'code' => 'AC{mm}{año}',
            'description' => 'Arriendo de local comercial farmacia, correspondiente al mes de {mes} {año}',
            'quantity' => 1,
            'price' => 500,
            'iva' => 4,
        ]],
    ]));
    $recurring->update(['next_run_at' => '2026-09-09 06:00']);

    recurringRunDaily();
    $this->travelTo(CarbonImmutable::parse('2026-11-09 06:00'));
    recurringRunDaily();

    $items = OrderItem::orderBy('id')->get(['product_id', 'code', 'description']);

    expect($items->pluck('product_id')->unique()->all())->toBe([$product->id])
        ->and($items->pluck('code')->all())->toBe(['AC102026', 'AC112026'])
        ->and($items->first()->description)->toBe('Arriendo de local comercial farmacia, correspondiente al mes de octubre 2026');

    $order = Order::withoutGlobalScope('branch')->orderBy('id')->firstOrFail();
    $this->actingAs($recurring->user);

    $this->getJson(route('orders.edit', $order))
        ->assertOk()
        ->assertJsonPath('order_items.0.code', 'AC102026')
        ->assertJsonPath('order_items.0.name', 'Arriendo de local comercial farmacia, correspondiente al mes de octubre 2026');

    $xml = (fn () => $this->buildXml($order->id, Company::sole()))->call(app(OrderLifecycleService::class));

    expect($xml)->toContain('<codigoPrincipal>AC102026</codigoPrincipal>')
        ->toContain('<descripcion>Arriendo de local comercial farmacia, correspondiente al mes de octubre 2026</descripcion>');

    $pdfItems = (fn () => $this->getMovementItems($order->id))->call(app(OrderPdfService::class));

    expect($pdfItems->first()->only(['code', 'name']))->toBe([
        'code' => 'AC102026',
        'name' => 'Arriendo de local comercial farmacia, correspondiente al mes de octubre 2026',
    ]);
});

test('editar la factura generada conserva el codigo y descripcion de la linea', function () {
    [, , $customer, $point, $product] = recurringSetup();
    recurringCreate(recurringPayload($customer, $point, $product, [
        'products' => [['product_id' => $product->id, 'code' => 'AC{mm}{año}', 'quantity' => 1, 'price' => 100, 'iva' => 4]],
    ]));
    recurringRunDaily();

    $order = Order::withoutGlobalScope('branch')->sole();
    $this->actingAs(User::sole());

    app(OrderUpdateService::class)->updateOrder($order, [
        'customer_id' => $customer->id,
        'date' => '2026-10-09',
        'products' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 100, 'discount' => 0, 'iva' => 4]],
        'aditionals' => [],
    ]);

    expect(OrderItem::sole()->only(['code', 'quantity']))->toBe(['code' => 'AC102026', 'quantity' => 2.0]);
});

test('el codigo de la linea no puede pasar del limite del SRI con el mes reemplazado', function () {
    [, , $customer, $point, $product] = recurringSetup();

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product, [
        'products' => [['product_id' => $product->id, 'code' => 'ARRIENDO-LOCAL-{mes}-{año}', 'quantity' => 1, 'price' => 100, 'iva' => 4]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['products.0.code' => 'Con el mes reemplazado supera los 25 caracteres que acepta el SRI.']);
});

test('la venta normal ignora codigo y descripcion propios en las lineas', function () {
    [, , $customer, $point, $product] = recurringSetup();

    $this->postJson(route('orders.store'), [
        'customer_id' => $customer->id,
        'total' => 115,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => $point->id,
        'date' => '2026-10-09',
        'pay_method' => 20,
        'payment_type' => PaymentTypes::CREDITO,
        'due_date' => '2026-10-24',
        'products' => [['product_id' => $product->id, 'code' => 'HACK', 'description' => 'Otro texto', 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 4]],
        'aditionals' => [],
    ])->assertSuccessful();

    expect(OrderItem::sole()->only(['code', 'description']))->toBe(['code' => null, 'description' => null]);
});

test('se emite a la hora programada y un fallo se reintenta cada hora', function () {
    [, , $customer, $point, $product] = recurringSetup();
    $recurring = recurringCreate(recurringPayload($customer, $point, $product, ['start_at' => '2026-10-09 08:30']));

    expect($recurring->next_run_at->format('Y-m-d H:i'))->toBe('2026-10-09 08:30');

    $this->travelTo(CarbonImmutable::parse('2026-10-09 08:29'));
    recurringRunDaily();
    expect(RecurringInvoiceRun::count())->toBe(0);

    Company::sole()->subscriptions()->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 08:30'));
    recurringRunDaily();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00'));
    recurringRunDaily();

    expect(RecurringInvoiceRun::sole()->attempts)->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:31'));
    recurringRunDaily();

    expect(RecurringInvoiceRun::sole()->attempts)->toBe(2);
});

test('la fecha fin no puede ser anterior al inicio', function () {
    [, , $customer, $point, $product] = recurringSetup();

    $this->postJson(route('recurring-invoices.store'), recurringPayload($customer, $point, $product, ['end_date' => '2026-10-08']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});
