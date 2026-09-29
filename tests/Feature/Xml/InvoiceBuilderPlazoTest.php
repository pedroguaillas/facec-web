<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\VoucherType;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use App\Xml\InvoiceBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Construye el mismo objeto $order (con columnas de customer unidas) y
 * $order_items que usa OrderLifecycleService::buildXml(), para poder llamar
 * a InvoiceBuilder::build() de forma aislada.
 */
function buildInvoiceXmlFor(array $orderOverrides): string
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $customer = Customer::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);
    EmisionPoint::factory()->for($branch)->create();

    DB::table('iva_taxes')->insertOrIgnore(['code' => 0, 'percentage' => 0, 'state' => 'active']);

    $product = Product::factory()->create(['branch_id' => $branch->id, 'iva' => 0]);

    $order = Order::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
        'serie' => '001-001-000000001',
        'total' => 100,
    ], $orderOverrides));

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 100,
        'discount' => 0,
        'ice' => 0,
        'iva' => 0,
    ]);

    $orderForXml = Order::join('customers AS c', 'c.id', 'customer_id')
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

test('factura a crédito incluye <plazo>/<unidadTiempo> en el XML', function () {
    $xml = buildInvoiceXmlFor([
        'payment_type' => PaymentTypes::CREDITO,
        'expiration_days' => 30,
        'due_date' => now()->addDays(30),
    ]);

    expect($xml)->toContain('<plazo>30</plazo>')
        ->toContain('<unidadTiempo>dias</unidadTiempo>');
});

test('factura de contado NO incluye <plazo>/<unidadTiempo> en el XML', function () {
    $xml = buildInvoiceXmlFor([
        'payment_type' => PaymentTypes::CONTADO,
        'expiration_days' => 0,
    ]);

    expect($xml)->not->toContain('<plazo>')
        ->not->toContain('<unidadTiempo>');
});
