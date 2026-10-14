<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Shop\Shop;
use App\Models\User;
use App\Models\UserType;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use App\Xml\CreditNoteBuilder;
use App\Xml\InvoiceBuilder;
use App\Xml\SettlementOnPurchaseBuilder;

const ARTISAN_FIELD = '<campoAdicional nombre="Artesano Calificado">Nro. JNDA-12345</campoAdicional>';

/**
 * @return array{0: Company, 1: Order}
 */
function artisanOrder(?string $artisanNumber, string $voucherSlug = VoucherTypes::VENTA_FACTURA): array
{
    $company = Company::factory()->create(['artisan_number' => $artisanNumber]);
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', $voucherSlug)->value('id'),
        'serie' => '001-001-000000001',
        'total' => 0,
    ]);

    $orderForXml = Order::join('customers AS c', 'c.id', 'customer_id')
        ->select('c.identication', 'c.name', 'c.address', 'c.type_identification', 'orders.*')
        ->where('orders.id', $order->id)
        ->first();

    return [$company, $orderForXml];
}

test('factura de un artesano lleva su número en infoAdicional, antes de los campos del usuario', function () {
    [$company, $order] = artisanOrder('JNDA-12345');
    OrderAditional::create(['order_id' => $order->id, 'name' => 'Email', 'description' => 'cliente@test.com']);

    $xml = (new InvoiceBuilder($company, $order, collect()))->build();

    expect($xml)->toContain('<infoAdicional>'.ARTISAN_FIELD.'<campoAdicional nombre="Email">cliente@test.com</campoAdicional></infoAdicional>');
});

test('factura de un artesano sin campos adicionales igual arma infoAdicional', function () {
    [$company, $order] = artisanOrder('JNDA-12345');

    $xml = (new InvoiceBuilder($company, $order, collect()))->build();

    expect($xml)->toContain('<infoAdicional>'.ARTISAN_FIELD.'</infoAdicional>');
});

test('factura de una empresa que no es artesana no lleva el campo', function () {
    [$company, $order] = artisanOrder(null);

    $xml = (new InvoiceBuilder($company, $order, collect()))->build();

    expect($xml)->not->toContain('Artesano Calificado')
        ->not->toContain('<infoAdicional>');
});

test('nota de crédito de un artesano lleva su número', function () {
    [$company, $order] = artisanOrder('JNDA-12345', VoucherTypes::VENTA_NOTA_CREDITO);
    $order->serie_order = '001-001-000000009';
    $order->date_order = now()->format('Y-m-d');
    $order->reason = 'Devolución';

    $xml = (new CreditNoteBuilder($company, $order, collect()))->build();

    expect($xml)->toContain('<infoAdicional>'.ARTISAN_FIELD.'</infoAdicional></notaCredito>');
});

test('liquidación de compra de un artesano lleva su número junto al RUC proveedor', function () {
    config(['services.order.ruc_proveedor' => '1234567890001']);
    $company = Company::factory()->create(['artisan_number' => 'JNDA-12345']);
    $branch = Branch::factory()->for($company)->create(['store' => 1]);

    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id'),
        'serie' => '001-001-000000001',
    ]);
    $shop->type_identification = 'cédula';
    $shop->name = 'Proveedor Test';
    $shop->identication = '0650243678';

    $xml = (new SettlementOnPurchaseBuilder($company, $shop, collect()))->build();

    expect($xml)->toContain('<infoAdicional>'.ARTISAN_FIELD.'<campoAdicional nombre="RUC Proveedor">1234567890001</campoAdicional>');
});

test('el cliente registra y borra su número de calificación artesanal', function () {
    $company = Company::factory()->create();
    Branch::factory()->for($company)->create();
    $user = User::factory()->create(['user_type_id' => UserType::firstOrCreate(['type' => 'client'])->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $company->id]);
    $this->actingAs($user);

    $this->putJson(route('settings.company.update'), ['artisan_number' => 'JNDA-12345'])
        ->assertOk()
        ->assertJsonPath('artisan_number', 'JNDA-12345');

    $this->putJson(route('settings.company.update'), ['artisan_number' => ''])
        ->assertOk()
        ->assertJsonPath('artisan_number', null);

    $this->putJson(route('settings.company.update'), ['artisan_number' => '123<&>'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['artisan_number' => 'solo admite letras, números']);
});

test('el PDF muestra el número de artesano en la información adicional', function () {
    $company = Company::factory()->make(['artisan_number' => 'JNDA-12345']);

    $html = view('vouchers.theme.adiinfopayment', ['company' => $company, 'orderaditionals' => collect()])->render();

    expect($html)->toContain('INFORMACIÓN ADICIONAL')
        ->toContain('Artesano Calificado')
        ->toContain('Nro. JNDA-12345');
});

test('el PDF sin artesano ni campos adicionales no muestra la tabla', function () {
    $company = Company::factory()->make(['artisan_number' => null]);

    $html = view('vouchers.theme.adiinfopayment', ['company' => $company, 'orderaditionals' => collect()])->render();

    expect($html)->not->toContain('INFORMACIÓN ADICIONAL');
});

test('el PDF de liquidación muestra el número de artesano; retención y guía no', function () {
    $company = Company::factory()->make(['artisan_number' => 'JNDA-12345']);

    expect(view('vouchers.theme.rucproveedor', ['company' => $company, 'showArtisan' => true])->render())
        ->toContain('Nro. JNDA-12345')
        ->and(view('vouchers.theme.rucproveedor', ['company' => $company])->render())
        ->not->toContain('Artesano Calificado');
});
