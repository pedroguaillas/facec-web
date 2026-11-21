<?php

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Treasury\BankAccount;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Database\Seeders\MethodOfPaymentSeeder;
use Database\Seeders\TaxesTableSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([MethodOfPaymentSeeder::class, TaxesTableSeeder::class]);
    Storage::fake();
});

/**
 * Factura de 100 a un cliente RUC. `$payload` recibe la cuenta bancaria de
 * la empresa y devuelve los campos a sobrescribir.
 *
 * @return array{company: Company, branch: Branch, customer: Customer, bank: BankAccount, order: Order}
 */
function retentionSale(?Closure $payload = null): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $company = Company::factory()->create(['ruc' => '1790000000001']);
    $branch = Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    test()->actingAs($user);

    $customer = Customer::factory()->create(['branch_id' => $branch->id, 'identication' => '0990000000001', 'type_identification' => 'ruc']);
    $bank = BankAccount::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    test()->postJson(route('orders.store'), array_merge([
        'customer_id' => $customer->id,
        'total' => 100,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'date' => now()->subDays(2)->format('Y-m-d'),
        'due_date' => now()->addDays(30)->format('Y-m-d'),
        'payment_type' => PaymentTypes::CREDITO,
        'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'iva' => 0]],
        'aditionals' => [],
    ], $payload ? $payload($bank) : []))->assertCreated();

    return ['company' => $company, 'branch' => $branch, 'customer' => $customer, 'bank' => $bank, 'order' => Order::firstOrFail()];
}

/**
 * @return array<string, mixed>
 */
function manualRetentionPayload(array $overrides = []): array
{
    return array_merge([
        'serie' => '001-002-000000123',
        'date' => now()->format('Y-m-d'),
        'authorization' => '2111202607099000000000120010020000001231234567813',
        'items' => [
            ['code' => 1, 'tax_code' => '312', 'base' => 100, 'porcentage' => 1.75, 'value' => 1.75],
            ['code' => 2, 'tax_code' => '1', 'base' => 12, 'porcentage' => 30, 'value' => 3.60],
        ],
    ], $overrides);
}

/**
 * XML autorizado (v2.0.0) tal como lo descarga el SRI.
 */
function authorizedRetentionXml(string $issuerRuc, string $subjectRuc, string $docSustento, string $estado = 'AUTORIZADO'): string
{
    $comprobante = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<comprobanteRetencion id="comprobante" version="2.0.0">'
        .'<infoTributaria><ambiente>2</ambiente><tipoEmision>1</tipoEmision><razonSocial>CLIENTE SA</razonSocial>'
        ."<ruc>{$issuerRuc}</ruc><claveAcceso>2111202607099000000000120010020000001231234567813</claveAcceso>"
        .'<codDoc>07</codDoc><estab>001</estab><ptoEmi>002</ptoEmi><secuencial>000000123</secuencial><dirMatriz>X</dirMatriz></infoTributaria>'
        .'<infoCompRetencion><fechaEmision>'.now()->format('d/m/Y').'</fechaEmision><obligadoContabilidad>SI</obligadoContabilidad>'
        ."<tipoIdentificacionSujetoRetenido>04</tipoIdentificacionSujetoRetenido><parteRel>NO</parteRel><razonSocialSujetoRetenido>EMPRESA</razonSocialSujetoRetenido><identificacionSujetoRetenido>{$subjectRuc}</identificacionSujetoRetenido><periodoFiscal>".now()->format('m/Y').'</periodoFiscal></infoCompRetencion>'
        .'<docsSustento>'
        ."<docSustento><codSustento>01</codSustento><codDocSustento>01</codDocSustento><numDocSustento>{$docSustento}</numDocSustento>"
        .'<retenciones>'
        .'<retencion><codigo>1</codigo><codigoRetencion>312</codigoRetencion><baseImponible>100.00</baseImponible><porcentajeRetener>1.75</porcentajeRetener><valorRetenido>1.75</valorRetenido></retencion>'
        .'<retencion><codigo>2</codigo><codigoRetencion>1</codigoRetencion><baseImponible>12.00</baseImponible><porcentajeRetener>30</porcentajeRetener><valorRetenido>3.60</valorRetenido></retencion>'
        .'</retenciones></docSustento>'
        .'<docSustento><codSustento>01</codSustento><codDocSustento>01</codDocSustento><numDocSustento>001001999999999</numDocSustento>'
        .'<retenciones><retencion><codigo>1</codigo><codigoRetencion>312</codigoRetencion><baseImponible>50.00</baseImponible><porcentajeRetener>1.75</porcentajeRetener><valorRetenido>0.88</valorRetenido></retencion></retenciones></docSustento>'
        .'</docsSustento></comprobanteRetencion>';

    return '<?xml version="1.0" encoding="UTF-8"?><autorizacion>'
        ."<estado>{$estado}</estado><numeroAutorizacion>2111202607099000000000120010020000001231234567813</numeroAutorizacion>"
        .'<fechaAutorizacion>2026-11-21T10:00:00-05:00</fechaAutorizacion><ambiente>PRODUCCIÓN</ambiente>'
        ."<comprobante><![CDATA[{$comprobante}]]></comprobante></autorizacion>";
}

function retentionXmlUpload(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('retencion.xml', $content);
}

test('registrar retención manual sobre factura a crédito abona la cartera y reduce el saldo', function () {
    ['order' => $order] = retentionSale();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())
        ->assertCreated()
        ->assertJsonPath('retention.serie', '001-002-000000123')
        ->assertJsonPath('retention.total', 5.35)
        ->assertJsonPath('retention.pending', 94.65);

    $order->refresh();
    $movement = AccountReceivableMovement::where('order_id', $order->id)->where('type', AccountsReceivableMovementTypes::RETENCION)->sole();

    expect($order->retentionItems)->toHaveCount(2)
        ->and($order->rent_retention)->toBe(1.75)
        ->and($order->iva_retention)->toBe(3.6)
        ->and((float) $movement->haber)->toBe(5.35)
        ->and((float) $movement->new_balance)->toBe(94.65);
});

test('importar el XML autorizado del cliente registra solo las líneas de esta factura sin guardar el archivo', function () {
    ['order' => $order, 'company' => $company, 'customer' => $customer] = retentionSale();
    $xml = authorizedRetentionXml($customer->identication, $company->ruc, str_replace('-', '', $order->serie));

    test()->post(route('orders.retention.import', $order), ['xml' => retentionXmlUpload($xml)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('retention.serie', '001-002-000000123')
        ->assertJsonPath('retention.authorization', '2111202607099000000000120010020000001231234567813')
        ->assertJsonPath('retention.total', 5.35);

    $order->refresh();

    expect($order->retentionItems)->toHaveCount(2)
        ->and($order->date_retention)->toBe(now()->format('Y-m-d'));

    expect(Storage::allFiles())->toBe([]);
});

test('importar XML de retención en versión 1.0.0', function () {
    ['order' => $order, 'company' => $company, 'customer' => $customer] = retentionSale();
    $doc = str_replace('-', '', $order->serie);
    $xml = '<?xml version="1.0" encoding="UTF-8"?><comprobanteRetencion id="comprobante" version="1.0.0">'
        ."<infoTributaria><razonSocial>CLIENTE</razonSocial><ruc>{$customer->identication}</ruc><claveAcceso>1111111111222222222233333333334444444444555555555</claveAcceso><estab>002</estab><ptoEmi>001</ptoEmi><secuencial>000000045</secuencial></infoTributaria>"
        .'<infoCompRetencion><fechaEmision>'.now()->format('d/m/Y')."</fechaEmision><identificacionSujetoRetenido>{$company->ruc}</identificacionSujetoRetenido></infoCompRetencion>"
        ."<impuestos><impuesto><codigo>1</codigo><codigoRetencion>303</codigoRetencion><baseImponible>100</baseImponible><porcentajeRetener>10</porcentajeRetener><valorRetenido>10.00</valorRetenido><codDocSustento>01</codDocSustento><numDocSustento>{$doc}</numDocSustento></impuesto></impuestos>"
        .'</comprobanteRetencion>';

    test()->post(route('orders.retention.import', $order), ['xml' => retentionXmlUpload($xml)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('retention.serie', '002-001-000000045')
        ->assertJsonPath('retention.authorization', '1111111111222222222233333333334444444444555555555')
        ->assertJsonPath('retention.renta', 10);
});

test('importar rechaza retención de otro cliente, a otra empresa, de otra factura o no autorizada', function (string $case) {
    ['order' => $order, 'company' => $company, 'customer' => $customer] = retentionSale();
    $doc = str_replace('-', '', $order->serie);

    $xml = match ($case) {
        'otro cliente' => authorizedRetentionXml('1700000000001', $company->ruc, $doc),
        'otra empresa' => authorizedRetentionXml($customer->identication, '1700000000001', $doc),
        'otra factura' => authorizedRetentionXml($customer->identication, $company->ruc, '001001000000777'),
        'no autorizada' => authorizedRetentionXml($customer->identication, $company->ruc, $doc, 'NO AUTORIZADO'),
        'no es xml' => 'esto no es xml',
    };

    test()->post(route('orders.retention.import', $order), ['xml' => retentionXmlUpload($xml)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('succes', false);

    expect($order->fresh()->serie_retencion)->toBeNull()
        ->and(AccountReceivableMovement::where('type', AccountsReceivableMovementTypes::RETENCION)->count())->toBe(0);
})->with(['otro cliente', 'otra empresa', 'otra factura', 'no autorizada', 'no es xml']);

test('venta de contado registra la retención sin tocar la cartera', function () {
    ['order' => $order] = retentionSale(fn (BankAccount $bank) => [
        'payment_type' => PaymentTypes::CONTADO,
        'due_date' => null,
        'pay_method' => 20,
        'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
        'source_id' => $bank->id,
    ]);

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())->assertCreated();

    expect($order->fresh()->retentionTotal())->toBe(5.35)
        ->and(AccountReceivableMovement::count())->toBe(0);
});

test('venta mixta: efectivo neto + crédito por la retención queda saldada al registrar la retención', function () {
    ['order' => $order] = retentionSale(fn (BankAccount $bank) => [
        'payment_type' => null,
        'payments' => [
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CONTADO, 'amount' => 94.65, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $bank->id],
            ['pay_method' => 20, 'payment_type' => PaymentTypes::CREDITO, 'amount' => 5.35],
        ],
    ]);

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())
        ->assertCreated()
        ->assertJsonPath('retention.pending', 0);

    expect((float) AccountReceivableMovement::where('order_id', $order->id)->orderByDesc('id')->value('new_balance'))->toBe(0.0);

    test()->travel(40)->days();
    test()->getJson(route('customers.overdue'))->assertOk()->assertJsonPath('total_overdue', 0)->assertJsonCount(0, 'parties');
});

test('rechaza retención mayor al saldo pendiente, duplicada, con código inexistente o sobre factura anulada', function () {
    ['order' => $order, 'bank' => $bank] = retentionSale();

    test()->postJson(route('orders.payments.store', $order), ['amount' => 98, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $bank->id])->assertCreated();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())
        ->assertUnprocessable()->assertJsonPath('message', 'El valor retenido es mayor al saldo pendiente de la factura.');

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload([
        'items' => [['code' => 2, 'tax_code' => '312', 'base' => 12, 'porcentage' => 30, 'value' => 1]],
    ]))->assertUnprocessable()->assertJsonPath('message', 'El código de retención 312 no existe para iva.');

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload([
        'items' => [['code' => 1, 'tax_code' => '312', 'base' => 100, 'porcentage' => 1.75, 'value' => 1.75]],
    ]))->assertCreated();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())
        ->assertUnprocessable()->assertJsonPath('message', 'La factura ya tiene registrada la retención 001-002-000000123.');
});

test('el abono posterior no puede superar el saldo descontada la retención', function () {
    ['order' => $order, 'bank' => $bank] = retentionSale();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())->assertCreated();

    test()->postJson(route('orders.payments.store', $order), ['amount' => 100, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $bank->id])
        ->assertUnprocessable();

    test()->postJson(route('orders.payments.store', $order), ['amount' => 94.65, 'source_type' => MovementPartyRegistry::BANK_ACCOUNT, 'source_id' => $bank->id])
        ->assertCreated();

    expect($order->fresh()->pendingBalance())->toBe(0.0);
});

test('eliminar la retención revierte el abono en cartera y limpia la factura', function () {
    ['order' => $order] = retentionSale();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())->assertCreated();
    test()->deleteJson(route('orders.retention.destroy', $order))->assertOk()->assertJsonPath('retention.serie', null);

    $order->refresh();

    expect($order->retentionItems()->count())->toBe(0)
        ->and($order->retentionTotal())->toBe(0.0)
        ->and((float) AccountReceivableMovement::where('order_id', $order->id)->orderByDesc('id')->value('new_balance'))->toBe(100.0);

    test()->getJson(route('orders.edit', $order))->assertOk()->assertJsonPath('retention', null);
});

test('el detalle de la venta incluye la retención registrada', function () {
    ['order' => $order] = retentionSale();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())->assertCreated();

    test()->getJson(route('orders.edit', $order))
        ->assertOk()
        ->assertJsonPath('retention.serie', '001-002-000000123')
        ->assertJsonCount(2, 'retention.items');
});

test('el listado de ventas trae lo retenido y el pendiente descontada la retención', function () {
    ['order' => $order] = retentionSale();

    test()->postJson(route('orders.retention.store', $order), manualRetentionPayload())->assertCreated();

    test()->getJson(route('orders.index'))
        ->assertOk()
        ->assertJsonPath('data.0.atts.retained', 5.35)
        ->assertJsonPath('data.0.atts.pending', 94.65);
});
