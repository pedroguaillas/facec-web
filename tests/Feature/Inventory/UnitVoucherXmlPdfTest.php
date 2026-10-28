<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\VoucherType;
use App\Services\Order\OrderLifecycleService;
use App\Services\ReferralGuide\ReferralGuideLifecycleService;
use App\Services\Shop\ShopLcXmlService;
use App\StaticClasses\VoucherTypes;
use App\Xml\Concerns\RendersDetallesAdicionales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/InventoryTestHelpers.php';

const UNIT_SRI_DETAILS = '<detallesAdicionales><detAdicional nombre="Marca" valor="M1"/><detAdicional nombre="Chasis" valor="5452"/></detallesAdicionales>';

function unitInvoiceXml(Company $company, int $orderId): string
{
    // El tipo de identificación aleatorio del factory puede no tener código SRI.
    Order::findOrFail($orderId)->customer()->update(['type_identification' => 'ruc']);

    return (fn () => $this->buildXml($orderId, $company))->call(app(OrderLifecycleService::class));
}

function unitSettlement(Branch $branch, Product $product): Shop
{
    $shop = Shop::factory()->create([
        'branch_id' => $branch->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id'),
        'serie' => '001-001-000000001',
    ]);
    ShopItem::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 1800, 'discount' => 0, 'iva' => 0]);

    return $shop;
}

function unitReferralGuide(Branch $branch, Product $product): ReferralGuide
{
    $carrier = Carrier::create([
        'branch_id' => $branch->id, 'type_identification' => '05', 'identication' => '0999999999',
        'name' => 'Transportista Test', 'license_plate' => 'ABC-1234',
    ]);
    $guide = ReferralGuide::create([
        'branch_id' => $branch->id,
        'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        'carrier_id' => $carrier->id,
        'serie' => '001-001-000000001',
        'date' => now()->toDateString(),
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->toDateString(),
        'date_end' => now()->addDay()->toDateString(),
        'reason_transfer' => 'Venta',
    ]);
    ReferralGuideItem::create(['referral_guide_id' => $guide->id, 'product_id' => $product->id, 'quantity' => 1]);

    return $guide;
}

beforeEach(function () {
    DB::table('iva_taxes')->insertOrIgnore([['code' => 0, 'percentage' => 0, 'state' => 'active']]);
});

test('la factura lleva en el detalle las características in_sri de la foto, antes de impuestos', function () {
    [$branch, $company] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated()->json('id');

    $xml = unitInvoiceXml($company, $orderId);

    expect($xml)->toContain(UNIT_SRI_DETAILS.'<impuestos>')
        ->not->toContain('nombre="Motor"');
});

test('una factura sin características genera el detalle sin detallesAdicionales', function () {
    [$branch, $company] = voucherInventoryCompany(['allow_negative_stock' => true]);
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [stockProduct($branch)]))->assertCreated()->json('id');

    expect(unitInvoiceXml($company, $orderId))
        ->not->toContain('detallesAdicionales')
        ->toMatch('#</precioTotalSinImpuesto><impuestos>#');
});

test('la nota de crédito no lleva detallesAdicionales aunque la línea tenga características', function () {
    [$branch, $company] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated();
    $creditNoteId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit], VoucherTypes::VENTA_NOTA_CREDITO))->assertCreated()->json('id');
    OrderItem::where('order_id', $creditNoteId)->update(['details' => json_encode([['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 1]])]);

    expect(unitInvoiceXml($company, $creditNoteId))->not->toContain('detallesAdicionales');
});

test('la liquidación de compra lleva las características in_sri de la unidad', function () {
    [$branch, $company] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $shop = unitSettlement($branch, $unit);

    $xml = (fn () => $this->buildXml($company, $shop->id))->call(app(ShopLcXmlService::class));

    expect($xml)->toContain(UNIT_SRI_DETAILS.'<impuestos>');
});

test('la guía de remisión lleva las características in_sri de la unidad', function () {
    [$branch, $company] = voucherInventoryCompany();
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $guide = unitReferralGuide($branch, $unit);

    $xml = (fn () => $this->buildXml($guide->id, $company))->call(app(ReferralGuideLifecycleService::class));

    expect($xml)->toContain('<cantidad>1</cantidad>'.UNIT_SRI_DETAILS.'</detalle>');
});

test('detallesAdicionales ordena por sort, toma hasta 3 in_sri y escapa los valores', function () {
    $renderer = new class
    {
        use RendersDetallesAdicionales;

        public function render(array $characteristics): string
        {
            return $this->renderDetallesAdicionales($characteristics);
        }
    };

    expect($renderer->render([
        ['label' => 'RAMV', 'value' => 'R-1', 'in_sri' => true, 'sort' => 4],
        ['label' => 'Marca', 'value' => 'M&M "Sport"', 'in_sri' => true, 'sort' => 1],
        ['label' => 'Color', 'value' => 'Rojo', 'in_sri' => false, 'sort' => 2],
        ['label' => 'Chasis', 'value' => '<5452>', 'in_sri' => true, 'sort' => 3],
        ['label' => 'Motor', 'value' => '45262', 'in_sri' => true, 'sort' => 5],
    ]))->toBe('<detallesAdicionales>'
        .'<detAdicional nombre="Marca" valor="M&amp;M &quot;Sport&quot;"/>'
        .'<detAdicional nombre="Chasis" valor="&lt;5452&gt;"/>'
        .'<detAdicional nombre="RAMV" valor="R-1"/>'
        .'</detallesAdicionales>')
        ->and($renderer->render([]))->toBe('');
});

test('los PDF de factura, liquidación y guía muestran todas las características', function () {
    [$branch] = voucherInventoryCompany();
    $branch->update(['store' => 1]);
    $unit = saleMotoUnit(saleMotoModel($branch), '5452', '45262');
    $orderId = $this->postJson(route('orders.store'), unitSalePayload($branch, [$unit]))->assertCreated()->json('id');
    Order::whereKey($orderId)->update(['serie' => '001-001-000000001']);
    $shop = unitSettlement($branch, $unit);
    $guide = unitReferralGuide($branch, $unit);

    $renderedDetails = [];
    View::composer('vouchers.theme.itemdetails', function ($view) use (&$renderedDetails) {
        $renderedDetails[] = $view->getData()['details'];
    });

    $this->get(route('orders.pdf', $orderId))->assertOk();
    $this->get(route('shops.pdf', $shop))->assertOk();
    $this->get(route('referralguides.pdf', $guide->id))->assertOk();

    expect($renderedDetails)->toHaveCount(3);

    foreach ($renderedDetails as $details) {
        expect(view('vouchers.theme.itemdetails', ['details' => $details])->render())
            ->toContain('Marca: M1 | Chasis: 5452 | Motor: 45262');
    }
});

test('ver la guía devuelve parent_product_id y características de cada línea', function () {
    [$branch] = voucherInventoryCompany();
    $model = saleMotoModel($branch);
    $unit = saleMotoUnit($model, '5452', '45262');
    $guide = unitReferralGuide($branch, $unit);

    $response = $this->getJson(route('referralguides.show', $guide))
        ->assertOk()
        ->assertJsonPath('referralguide_items.0.product_id', $unit->id)
        ->assertJsonPath('referralguide_items.0.parent_product_id', $model->id);

    expect($response->json('referralguide_items.0.characteristics'))->toEqual([
        ['label' => 'Marca', 'value' => 'M1', 'in_sri' => true, 'sort' => 1],
        ['label' => 'Chasis', 'value' => '5452', 'in_sri' => true, 'sort' => 2],
        ['label' => 'Motor', 'value' => '45262', 'in_sri' => false, 'sort' => 3],
    ]);
});
