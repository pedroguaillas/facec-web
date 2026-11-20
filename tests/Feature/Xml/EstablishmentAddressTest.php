<?php

use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use App\Xml\CreditNoteBuilder;
use App\Xml\InvoiceBuilder;
use App\Xml\ReferralGuideBuilder;
use App\Xml\RetentionBuilder;
use App\Xml\SettlementOnPurchaseBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Empresa con la sucursal 001 creada primero (donde se guardan las ventas,
 * BranchScope) y la matriz 002 creada después.
 *
 * @return array{0: Company, 1: Branch, 2: Branch}
 */
function establishmentsSetup(string $firstType = 'sucursal', string $secondType = 'matriz'): array
{
    $company = Company::factory()->create();
    $first = Branch::factory()->for($company)->create([
        'store' => 1, 'type' => $firstType, 'name' => 'Local Centro', 'address' => 'Av. Centro 100',
        'created_at' => now()->subDay(),
    ]);
    $second = Branch::factory()->for($company)->create([
        'store' => 2, 'type' => $secondType, 'name' => 'Local Norte', 'address' => 'Av. Norte & 200',
    ]);

    return [$company, $first, $second];
}

function establishmentInvoiceXml(Company $company, Branch $orderBranch, string $serie): string
{
    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);

    $customer = Customer::factory()->create(['branch_id' => $orderBranch->id, 'type_identification' => 'ruc']);
    $product = Product::factory()->create(['branch_id' => $orderBranch->id, 'iva' => 0]);

    $order = Order::factory()->create([
        'branch_id' => $orderBranch->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'serie' => $serie,
        'total' => 100,
    ]);

    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'discount' => 0, 'ice' => 0, 'iva' => 0]);

    $orderForXml = Order::withoutGlobalScope('branch')->join('customers AS c', 'c.id', 'customer_id')
        ->select('c.identication', 'c.name', 'c.address', 'c.type_identification', 'orders.*')
        ->where('orders.id', $order->id)
        ->first();

    $orderItems = OrderItem::join('products AS p', 'p.id', 'product_id')
        ->join('iva_taxes AS it', 'it.code', 'order_items.iva')
        ->selectRaw('quantity,price,discount,order_items.ice AS valice,p.code AS codeproduct,aux_cod,name,it.code AS iva,it.percentage,p.ice AS codice')
        ->where('order_id', $order->id)
        ->get();

    return (new InvoiceBuilder($company, $orderForXml, $orderItems))->build();
}

test('el XML usa nombre comercial y direccion del establecimiento de la serie y la direccion de la matriz', function () {
    [$company, $first] = establishmentsSetup();

    $xml = establishmentInvoiceXml($company, $first, '002-001-000000001');

    expect($xml)->toContain('<nombreComercial>Local Norte</nombreComercial>')
        ->toContain('<dirMatriz>Av. Norte &amp; 200</dirMatriz>')
        ->toContain('<fechaEmision>'.now()->format('d/m/Y').'</fechaEmision><dirEstablecimiento>Av. Norte &amp; 200</dirEstablecimiento>');
});

test('desde una sucursal, dirMatriz es la de la matriz y dirEstablecimiento la de la sucursal', function () {
    [$company, $first] = establishmentsSetup();

    $xml = establishmentInvoiceXml($company, $first, '001-001-000000001');

    expect($xml)->toContain('<nombreComercial>Local Centro</nombreComercial>')
        ->toContain('<dirMatriz>Av. Norte &amp; 200</dirMatriz>')
        ->toContain('<dirEstablecimiento>Av. Centro 100</dirEstablecimiento>');
});

test('sin establecimiento marcado como matriz, dirMatriz es el mas antiguo', function () {
    [$company, $first] = establishmentsSetup('sucursal', 'sucursal');

    $xml = establishmentInvoiceXml($company, $first, '002-001-000000001');

    expect($company->matrix()->id)->toBe($first->id)
        ->and($xml)->toContain('<dirMatriz>Av. Centro 100</dirMatriz>')
        ->toContain('<dirEstablecimiento>Av. Norte &amp; 200</dirEstablecimiento>');
});

test('forSerie resuelve el establecimiento por el estab de la serie, o el mas antiguo si no existe', function () {
    [$company, $first, $second] = establishmentsSetup();

    expect(Branch::forSerie($company, '002-001-000000001')->id)->toBe($second->id)
        ->and(Branch::forSerie($company, '009-001-000000001')->id)->toBe($first->id);
});

test('el RIDE muestra direccion matriz y sucursal cuando emite una sucursal', function () {
    [$company, $first] = establishmentsSetup();

    $html = view('vouchers.theme.company', ['company' => $company, 'branch' => $first, 'voucherTypeSlug' => VoucherTypes::VENTA_FACTURA])->render();

    expect($html)->toContain('Dir. Matriz')->toContain('Av. Norte &amp; 200')
        ->toContain('Dir. Sucursal')->toContain('Av. Centro 100');
});

test('el RIDE muestra una sola direccion cuando emite la matriz', function () {
    [$company, , $second] = establishmentsSetup();

    $html = view('vouchers.theme.company', ['company' => $company, 'branch' => $second, 'voucherTypeSlug' => VoucherTypes::VENTA_FACTURA])->render();

    expect($html)->toContain('Dirección')->toContain('Av. Norte &amp; 200')
        ->not->toContain('Dir. Matriz')->not->toContain('Dir. Sucursal');
});

test('nota de credito lleva dirEstablecimiento despues de fechaEmision', function () {
    [$company, $first] = establishmentsSetup();
    $customer = Customer::factory()->create(['branch_id' => $first->id, 'type_identification' => 'ruc']);
    $order = Order::factory()->create([
        'branch_id' => $first->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id'),
        'serie' => '002-001-000000001',
        'total' => 0,
    ]);
    $orderForXml = Order::withoutGlobalScope('branch')->join('customers AS c', 'c.id', 'customer_id')
        ->select('c.identication', 'c.name', 'c.address', 'c.type_identification', 'orders.*')
        ->where('orders.id', $order->id)
        ->first();
    $orderForXml->serie_order = '001-001-000000009';
    $orderForXml->date_order = now()->format('Y-m-d');
    $orderForXml->reason = 'Devolución';

    $xml = (new CreditNoteBuilder($company, $orderForXml, collect()))->build();

    expect($xml)->toMatch('#</fechaEmision><dirEstablecimiento>Av\. Norte &amp; 200</dirEstablecimiento><tipoIdentificacionComprador>#');
});

test('liquidacion de compra y retencion llevan dirEstablecimiento del establecimiento de su serie', function () {
    [$company, $first] = establishmentsSetup();
    $shop = Shop::factory()->create([
        'branch_id' => $first->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id'),
        'serie' => '002-001-000000001',
        'serie_retencion' => '001-001-000000001',
        'date_retention' => now()->format('Y-m-d'),
    ]);
    $shop->type_identification = 'ruc';
    $shop->name = 'Proveedor Test';
    $shop->identication = '1234567890001';

    $settlement = (new SettlementOnPurchaseBuilder($company, $shop, collect()))->build();
    $retention = (new RetentionBuilder($company, $shop))->build();

    expect($settlement)->toMatch('#</fechaEmision><dirEstablecimiento>Av\. Norte &amp; 200</dirEstablecimiento>#')
        ->and($retention)->toMatch('#</fechaEmision><dirEstablecimiento>Av\. Centro 100</dirEstablecimiento><obligadoContabilidad>#');
});

test('guia de remision lleva dirEstablecimiento como primer campo de infoGuiaRemision', function () {
    [$company, $first] = establishmentsSetup();
    $customer = Customer::factory()->create(['branch_id' => $first->id]);
    $carrier = Carrier::create([
        'branch_id' => $first->id,
        'type_identification' => '05',
        'identication' => '0999999999',
        'name' => 'Transportista Test',
        'license_plate' => 'ABC-1234',
    ]);
    $guide = ReferralGuide::create([
        'branch_id' => $first->id,
        'customer_id' => $customer->id,
        'carrier_id' => $carrier->id,
        'serie' => '002-001-000000001',
        'date' => now()->format('Y-m-d'),
        'address_from' => 'Origen',
        'address_to' => 'Destino',
        'date_start' => now()->format('Y-m-d'),
        'date_end' => now()->addDay()->format('Y-m-d'),
        'reason_transfer' => 'Venta',
    ]);
    $guide->ca_name = 'Transportista Test';
    $guide->ca_identication = '0650243678';
    $guide->license_plate = 'ABC-1234';

    $xml = (new ReferralGuideBuilder($company, $guide, collect()))->build();

    expect($xml)->toContain('<infoGuiaRemision><dirEstablecimiento>Av. Norte &amp; 200</dirEstablecimiento><dirPartida>');
});
