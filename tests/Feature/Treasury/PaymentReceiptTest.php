<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\Movement;
use App\Models\Treasury\PaymentReceipt;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: Branch, 1: BankAccount, 2: User}
 */
function receiptActingAsCompany(): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    return [$branch, BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 500]), $user];
}

function uploadReceipt(): int
{
    return test()->post(route('payment-receipts.store'), ['receipt' => UploadedFile::fake()->image('comprobante.png')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('id');
}

function receiptOrderPayload(Branch $branch, string $paymentType, array $overrides = []): array
{
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    return [
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => now()->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => $paymentType,
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
        ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake('local');
});

test('subir un comprobante lo guarda en disco privado sin vincular', function () {
    receiptActingAsCompany();

    $receipt = PaymentReceipt::findOrFail(uploadReceipt());

    expect($receipt->movement_id)->toBeNull()
        ->and($receipt->mime_type)->toBe('image/png');
    Storage::disk('local')->assertExists($receipt->path);
});

test('el comprobante solo acepta imágenes', function () {
    receiptActingAsCompany();

    test()->post(route('payment-receipts.store'), ['receipt' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');
});

test('abono por banco con receipt_id vincula el comprobante y se puede ver', function () {
    [$branch, $account] = receiptActingAsCompany();
    test()->postJson(route('orders.store'), receiptOrderPayload($branch, PaymentTypes::CREDITO))->assertCreated();
    $receiptId = uploadReceipt();

    test()->postJson(route('orders.payments.store', Order::first()), [
        'amount' => 40,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'receipt_id' => $receiptId,
    ])->assertCreated();

    $receipt = PaymentReceipt::findOrFail($receiptId);
    $movement = Movement::findOrFail($receipt->movement_id);

    expect($movement->receipt_path)->toBe($receipt->path);
    test()->getJson(route('movements.show', $movement))->assertJsonPath('atts.has_receipt', true);
    test()->get(route('movements.receipt', $movement))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('venta de contado por banco vincula el comprobante al cobro', function () {
    [$branch, $account] = receiptActingAsCompany();
    $receiptId = uploadReceipt();

    test()->postJson(route('orders.store'), receiptOrderPayload($branch, PaymentTypes::CONTADO, [
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'receipt_id' => $receiptId,
    ]))->assertCreated();

    expect(PaymentReceipt::findOrFail($receiptId)->movement_id)->toBe(Order::first()->movement_id);
});

test('anticipo a proveedor por banco vincula el comprobante', function () {
    [$branch, $account] = receiptActingAsCompany();
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    $receiptId = uploadReceipt();

    test()->postJson(route('providers.advances.store', $provider), [
        'amount' => 50,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'receipt_id' => $receiptId,
    ])->assertCreated();

    expect(PaymentReceipt::findOrFail($receiptId)->movement_id)->not->toBeNull();
});

test('receipt_id se rechaza sin banco, ya vinculado o de otra empresa', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    test()->postJson(route('orders.store'), receiptOrderPayload($branch, PaymentTypes::CREDITO))->assertCreated();
    $order = Order::first();
    $linked = PaymentReceipt::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'movement_id' => Movement::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id])->id]);
    $foreign = PaymentReceipt::factory()->create(['user_id' => $user->id]);
    $bankPayment = ['amount' => 10, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id];

    test()->postJson(route('orders.payments.store', $order), ['amount' => 10, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => 1, 'receipt_id' => uploadReceipt()])
        ->assertJsonValidationErrors('receipt_id');
    test()->postJson(route('orders.payments.store', $order), [...$bankPayment, 'receipt_id' => $linked->id])->assertJsonValidationErrors('receipt_id');
    test()->postJson(route('orders.payments.store', $order), [...$bankPayment, 'receipt_id' => $foreign->id])->assertJsonValidationErrors('receipt_id');
});

test('ver el comprobante de un movimiento sin archivo responde 404', function () {
    [$branch, $account, $user] = receiptActingAsCompany();

    test()->getJson(route('movements.receipt', Movement::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id])))->assertNotFound();
});

test('la purga borra solo los comprobantes sin vincular de más de 24h y su archivo', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    $old = PaymentReceipt::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'created_at' => now()->subHours(25)]);
    $recent = PaymentReceipt::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id]);
    $linked = PaymentReceipt::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'created_at' => now()->subDays(3),
        'movement_id' => Movement::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id])->id,
    ]);
    Storage::disk('local')->put($old->path, 'x');

    test()->artisan('model:prune', ['--model' => [PaymentReceipt::class]])->assertSuccessful();

    expect(PaymentReceipt::pluck('id')->all())->toEqualCanonicalizing([$recent->id, $linked->id]);
    Storage::disk('local')->assertMissing($old->path);
});

test('receipt_number se guarda en el movimiento del pago por banco y se rechaza sin banco', function () {
    [$branch, $account] = receiptActingAsCompany();
    test()->postJson(route('orders.store'), receiptOrderPayload($branch, PaymentTypes::CREDITO))->assertCreated();
    $order = Order::first();

    test()->postJson(route('orders.payments.store', $order), ['amount' => 10, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => 1, 'receipt_number' => 'TRX-1'])
        ->assertJsonValidationErrors('receipt_number');

    test()->postJson(route('orders.payments.store', $order), [
        'amount' => 10, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id, 'receipt_number' => 'TRX-1',
    ])->assertCreated();

    expect(Movement::where('origin_id', $order->id)->value('receipt_number'))->toBe('TRX-1');
});

test('venta de contado por banco guarda el receipt_number en el cobro', function () {
    [$branch, $account] = receiptActingAsCompany();

    test()->postJson(route('orders.store'), receiptOrderPayload($branch, PaymentTypes::CONTADO, [
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $account->id,
        'receipt_number' => 'DEP-99',
    ]))->assertCreated();

    expect(Movement::findOrFail(Order::first()->movement_id)->receipt_number)->toBe('DEP-99');
});

function bankMovement(Branch $branch, BankAccount $account, User $user, array $attributes = []): Movement
{
    return Movement::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $account->id, ...$attributes]);
}

test('editar el comprobante de un movimiento guardado sin él', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    $movement = bankMovement($branch, $account, $user);
    $receiptId = uploadReceipt();

    test()->patchJson(route('movements.receipt.update', $movement), ['receipt_number' => 'TRX-7', 'receipt_id' => $receiptId])
        ->assertOk()
        ->assertJsonPath('atts.receipt_number', 'TRX-7')
        ->assertJsonPath('atts.has_receipt', true);

    expect(PaymentReceipt::findOrFail($receiptId)->movement_id)->toBe($movement->id)
        ->and($movement->fresh()->receipt_path)->toBe(PaymentReceipt::findOrFail($receiptId)->path);
});

test('reemplazar el comprobante borra el archivo anterior y las claves ausentes no cambian', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    $movement = bankMovement($branch, $account, $user, ['receipt_number' => 'TRX-1']);
    $old = PaymentReceipt::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'movement_id' => $movement->id]);
    Storage::disk('local')->put($old->path, 'x');
    $movement->update(['receipt_path' => $old->path]);
    $newId = uploadReceipt();

    test()->patchJson(route('movements.receipt.update', $movement), ['receipt_id' => $newId])
        ->assertOk()
        ->assertJsonPath('atts.receipt_number', 'TRX-1');

    Storage::disk('local')->assertMissing($old->path);
    expect(PaymentReceipt::find($old->id))->toBeNull()
        ->and($movement->fresh()->receipt_path)->toBe(PaymentReceipt::findOrFail($newId)->path);
});

test('receipt_id y receipt_number en null quitan el comprobante', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    $movement = bankMovement($branch, $account, $user, ['receipt_number' => 'TRX-1', 'receipt_path' => 'treasury/receipts/manual.png']);
    Storage::disk('local')->put('treasury/receipts/manual.png', 'x');

    test()->patchJson(route('movements.receipt.update', $movement), ['receipt_id' => null, 'receipt_number' => ''])
        ->assertOk()
        ->assertJsonPath('atts.receipt_number', null)
        ->assertJsonPath('atts.has_receipt', false);

    Storage::disk('local')->assertMissing('treasury/receipts/manual.png');
});

test('editar el comprobante se rechaza sin banco o con un receipt_id ya vinculado', function () {
    [$branch, $account, $user] = receiptActingAsCompany();
    $cashMovement = Movement::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION, 'source_id' => 1]);
    $other = bankMovement($branch, $account, $user);
    $linked = PaymentReceipt::factory()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'movement_id' => $other->id]);

    test()->patchJson(route('movements.receipt.update', $cashMovement), ['receipt_number' => 'X'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Solo los movimientos con cuenta bancaria llevan comprobante.');
    test()->patchJson(route('movements.receipt.update', bankMovement($branch, $account, $user)), ['receipt_id' => $linked->id])
        ->assertJsonValidationErrors('receipt_id');
});
