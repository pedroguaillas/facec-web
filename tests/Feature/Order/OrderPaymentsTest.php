<?php

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use App\Xml\InvoiceBuilder;
use Database\Seeders\MethodOfPaymentSeeder;

beforeEach(fn () => $this->seed(MethodOfPaymentSeeder::class));

/**
 * @return array{branch: Branch, company: Company, customer: Customer, bank: BankAccount, session: CashRegisterSession}
 */
function multiPaymentSetup(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $cashRegister = CashRegister::factory()->create(['branch_id' => $branch->id, 'responsible_id' => $user->id, 'status' => CashRegisterStatuses::OPEN]);

    return [
        'branch' => $branch,
        'company' => $company,
        'customer' => Customer::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']),
        'bank' => BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]),
        'session' => CashRegisterSession::factory()->for($cashRegister)->create(['branch_id' => $branch->id, 'opened_by' => $user->id, 'current_balance' => 0]),
    ];
}

/**
 * @param  array{branch: Branch, customer: Customer}  $setup
 * @return array<string, mixed>
 */
function multiPaymentPayload(array $setup, array $overrides = []): array
{
    $product = Product::factory()->create(['branch_id' => $setup['branch']->id, 'iva' => 0]);

    return array_merge([
        'customer_id' => $setup['customer']->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($setup['branch'])->create()->id,
        'date' => now()->format('Y-m-d'),
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ], $overrides);
}

function invoiceXmlOf(Order $order): string
{
    $orderForXml = Order::join('customers AS c', 'c.id', 'customer_id')
        ->select('c.identication', 'c.name', 'c.address', 'c.type_identification', 'orders.*')
        ->where('orders.id', $order->id)
        ->first();

    $items = OrderItem::join('products AS p', 'p.id', 'product_id')
        ->join('iva_taxes AS it', 'it.code', 'order_items.iva')
        ->selectRaw('quantity,price,discount,order_items.ice AS valice,p.code AS codeproduct,aux_cod,name,it.code AS iva,it.percentage,p.ice AS codice')
        ->where('order_id', $order->id)
        ->get();

    return (new InvoiceBuilder(Branch::findOrFail($order->branch_id)->company, $orderForXml, $items))->build();
}

test('venta con efectivo y banco crea dos formas de pago, dos cobros y dos <pago> en el XML', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 40, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => $setup['session']->id],
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 60, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $setup['bank']->id, 'receipt_number' => 'TRX-1'],
        ],
    ]))->assertCreated();

    $order = Order::first();

    expect($order->payments)->toHaveCount(2)
        ->and($order->payment_type)->toBe(PaymentTypes::CONTADO)
        ->and($order->pay_method)->toBe(1)
        ->and((float) $order->paid)->toBe(100.0)
        ->and(Movement::where('origin_type', MovementPartyRegistry::ORDER)->where('origin_id', $order->id)->count())->toBe(2)
        ->and($setup['bank']->fresh()->balance)->toBe(60.0)
        ->and((float) $setup['session']->fresh()->current_balance)->toBe(40.0)
        ->and($order->movement_id)->toBe($order->payments->first()->movement_id)
        ->and(AccountReceivableMovement::count())->toBe(0);

    $xml = invoiceXmlOf($order);

    expect(substr_count($xml, '<pago>'))->toBe(2)
        ->and($xml)->toContain('<pago><formaPago>01</formaPago><total>40.00</total></pago>')
        ->toContain('<pago><formaPago>20</formaPago><total>60.00</total></pago>');
});

test('venta mixta contado + crédito carga a cartera solo la parte a crédito y el XML lleva plazo solo en esa línea', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 30, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => $setup['session']->id],
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CREDITO, 'amount' => 70],
        ],
    ]))->assertCreated();

    $order = Order::first();
    $receivable = AccountReceivableMovement::where('order_id', $order->id)->sole();

    expect($order->payment_type)->toBe(PaymentTypes::CREDITO)
        ->and((float) $order->paid)->toBe(30.0)
        ->and($order->expiration_days)->toBe(30)
        ->and($receivable->type)->toBe(AccountsReceivableMovementTypes::FACTURA)
        ->and((float) $receivable->debe)->toBe(70.0);

    $xml = invoiceXmlOf($order);

    expect($xml)->toContain('<pago><formaPago>01</formaPago><total>30.00</total></pago>')
        ->toContain('<pago><formaPago>20</formaPago><total>70.00</total><plazo>30</plazo><unidadTiempo>dias</unidadTiempo></pago>');
});

test('rechaza formas de pago que no suman el total', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 40, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => $setup['session']->id],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('payments');

    expect(Order::count())->toBe(0);
});

test('línea a crédito exige fecha de vencimiento y línea de contado exige caja/banco', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 50],
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CREDITO, 'amount' => 50],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'payments.0.source_type', 'payments.0.source_id']);
});

test('payload anterior (sin payments) genera una sola forma de pago por el total', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'pay_method' => 19,
        'payment_type' => PaymentTypes::CONTADO,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $setup['bank']->id,
    ]))->assertCreated();

    $order = Order::first();
    $payment = $order->payments()->sole();

    expect($payment->pay_method)->toBe(19)
        ->and($payment->amount)->toBe(100.0)
        ->and($payment->movement_id)->toBe($order->movement_id)
        ->and($setup['bank']->fresh()->balance)->toBe(100.0)
        ->and(invoiceXmlOf($order))->toContain('<pagos><pago><formaPago>19</formaPago><total>100.00</total></pago></pagos>');
});

test('venta anterior sin order_payments sigue generando un solo <pago> con pay_method', function () {
    $setup = multiPaymentSetup();

    $order = Order::factory()->create([
        'branch_id' => $setup['branch']->id,
        'customer_id' => $setup['customer']->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'serie' => '001-001-000000001',
        'total' => 100,
        'pay_method' => 20,
        'payment_type' => PaymentTypes::CONTADO,
    ]);

    expect(invoiceXmlOf($order))->toContain('<pagos><pago><formaPago>20</formaPago><total>100.00</total></pago></pagos>');
});

test('el detalle de la venta devuelve sus formas de pago', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 25, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => $setup['session']->id],
            ['pay_method' => 19, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 75, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $setup['bank']->id],
        ],
    ]))->assertCreated();

    test()->getJson(route('orders.edit', Order::first()))
        ->assertOk()
        ->assertJsonCount(2, 'order_payments')
        ->assertJsonPath('order_payments.1.pay_method', 19)
        ->assertJsonPath('order_payments.1.source_type', MovementPartyRegistry::BANK_ACCOUNT);
});

test('editar una venta con varias formas de pago no permite cambiar el total', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, [
        'payments' => [
            ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 50, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => $setup['session']->id],
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 50, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $setup['bank']->id],
        ],
    ]))->assertCreated();

    $order = Order::first();
    $productId = $order->orderitems()->value('product_id');

    test()->putJson(route('orders.update', $order), [
        'total' => 120,
        'products' => [['product_id' => $productId, 'quantity' => 1, 'price' => 120, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('total');
});

test('editar una venta con una sola forma de pago ajusta su monto al nuevo total', function () {
    $setup = multiPaymentSetup();

    test()->postJson(route('orders.store'), multiPaymentPayload($setup, ['pay_method' => 1, 'payment_type' => PaymentTypes::CONTADO, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $setup['bank']->id]))->assertCreated();

    $order = Order::first();
    $productId = $order->orderitems()->value('product_id');

    test()->putJson(route('orders.update', $order), [
        'total' => 120,
        'products' => [['product_id' => $productId, 'quantity' => 1, 'price' => 120, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ])->assertOk();

    expect($order->payments()->sole()->amount)->toBe(120.0);
});

test('el listado no muestra saldo pendiente en ventas de contado antiguas (paid = 0)', function () {
    $setup = multiPaymentSetup();

    Order::factory()->create([
        'branch_id' => $setup['branch']->id,
        'customer_id' => $setup['customer']->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'total' => 100,
        'paid' => 0,
        'payment_type' => PaymentTypes::CONTADO,
    ]);

    test()->getJson(route('orders.index'))->assertOk()->assertJsonPath('data.0.atts.pending', 0);
});
