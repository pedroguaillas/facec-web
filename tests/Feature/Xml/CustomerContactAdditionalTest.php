<?php

use App\Models\Company;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Services\Order\OrderLifecycleService;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';

function contactOrderXml(Company $company, int $orderId, array $customer): string
{
    Order::findOrFail($orderId)->customer()->update(['type_identification' => 'ruc', ...$customer]);

    return (fn () => $this->buildXml($orderId, $company))->call(app(OrderLifecycleService::class));
}

beforeEach(function () {
    DB::table('iva_taxes')->insertOrIgnore([['code' => 0, 'percentage' => 0, 'state' => 'active']]);
    [$this->branch, $this->company] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $this->product = stockProduct($this->branch);
});

test('la factura envía el correo y el teléfono del cliente en infoAdicional', function () {
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($this->branch, [$this->product]))->assertCreated()->json('id');

    expect(contactOrderXml($this->company, $orderId, ['email' => 'cliente@test.ec', 'phone' => '0991234567']))
        ->toContain('<campoAdicional nombre="Email">cliente@test.ec</campoAdicional><campoAdicional nombre="Teléfono">0991234567</campoAdicional>');
});

test('la factura omite el contacto vacío y el que ya está como adicional de la venta', function () {
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($this->branch, [$this->product]))->assertCreated()->json('id');
    OrderAditional::create(['order_id' => $orderId, 'name' => 'email', 'description' => 'otro@test.ec']);

    expect(contactOrderXml($this->company, $orderId, ['email' => 'cliente@test.ec', 'phone' => null]))
        ->not->toContain('cliente@test.ec')
        ->not->toContain('nombre="Teléfono"')
        ->toContain('<campoAdicional nombre="email">otro@test.ec</campoAdicional>');
});

test('la nota de crédito envía el contacto del cliente y escapa los valores', function () {
    $this->postJson(route('orders.store'), unitSalePayload($this->branch, [$this->product]))->assertCreated();
    $creditNoteId = $this->postJson(route('orders.store'), unitSalePayload($this->branch, [$this->product], VoucherTypes::VENTA_NOTA_CREDITO))->assertCreated()->json('id');

    expect(contactOrderXml($this->company, $creditNoteId, ['email' => null, 'phone' => '07 2&3']))
        ->toContain('<infoAdicional><campoAdicional nombre="Teléfono">07 2&amp;3</campoAdicional></infoAdicional>')
        ->not->toContain('nombre="Email"');
});

test('el PDF de la factura muestra el teléfono del cliente', function () {
    $this->branch->update(['store' => 1]);
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($this->branch, [$this->product]))->assertCreated()->json('id');
    Order::whereKey($orderId)->update(['serie' => '001-001-000000001']);
    Order::findOrFail($orderId)->customer()->update(['phone' => '0991234567']);

    $data = null;
    View::composer('vouchers.invoice', function ($view) use (&$data) {
        $data = $view->getData();
    });

    $this->get(route('orders.pdf', $orderId))->assertOk();

    expect(view('vouchers.invoice', $data)->render())->toContain('Teléfono: 0991234567');
});
