<?php

use App\Models\Branch;
use App\Models\EmisionPoint;
use App\Models\Inventory;
use App\Models\Product\Product;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Models\VoucherType;
use App\StaticClasses\InventoryMovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;

require_once __DIR__.'/InventoryTestHelpers.php';

function purchaseInvoice(Branch $branch, Product $product, float $quantity): Shop
{
    $provider = Provider::factory()->create(['branch_id' => $branch->id, 'type_identification' => 'ruc']);

    $response = test()->postJson(route('shops.store'), [
        'date' => '2026-10-01',
        'voucher_type_id' => VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id'),
        'provider_id' => $provider->id,
        'point_id' => EmisionPoint::factory()->for($branch)->create()->id,
        'serie' => '001-001-000123',
        'total' => $quantity * 5,
        'payment_type' => PaymentTypes::CREDITO,
        'due_date' => '2026-10-31',
        'products' => [[
            'product_id' => $product->id, 'quantity' => $quantity, 'price' => 5, 'discount' => 0, 'iva' => 0,
        ]],
    ])->assertCreated();

    return Shop::findOrFail($response->json('id'));
}

test('editar una compra factura devuelve sus líneas', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $shop = purchaseInvoice($branch, $product, 10);

    test()->getJson(route('shops.edit', $shop))
        ->assertSuccessful()
        ->assertJsonCount(1, 'shop_items')
        ->assertJsonPath('shop_items.0.product_id', $product->id)
        ->assertJsonPath('shop_items.0.code', $product->code);
});

test('actualizar las líneas de una compra re-sincroniza el kardex', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $shop = purchaseInvoice($branch, $product, 10);
    expect($product->fresh()->stock)->toBe(10.0);

    test()->putJson(route('shops.update', $shop), [
        'total' => 24,
        'products' => [[
            'product_id' => $product->id, 'quantity' => 6, 'price' => 4, 'discount' => 0, 'iva' => 0,
        ]],
    ])->assertSuccessful();

    $active = $shop->inventories()->active()->get();

    expect(ShopItem::where('shop_id', $shop->id)->value('quantity'))->toEqual(6)
        ->and($active)->toHaveCount(1)
        ->and($active->first()->unit_cost)->toBe(4.0)
        ->and($shop->inventories()->where('type', InventoryMovementTypes::REVERSAL)->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(6.0);
});

test('actualizar la compra sin products conserva líneas y kardex', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $shop = purchaseInvoice($branch, $product, 10);

    test()->putJson(route('shops.update', $shop), ['description' => 'Nota'])->assertSuccessful();

    expect(ShopItem::where('shop_id', $shop->id)->count())->toBe(1)
        ->and(Inventory::where('model_id', $shop->id)->where('model_type', 'shop')->count())->toBe(1)
        ->and($product->fresh()->stock)->toBe(10.0);
});

test('cambiar la fecha de la compra mueve sus movimientos a la nueva fecha', function () {
    [$branch] = voucherInventoryCompany();
    $product = stockProduct($branch);
    $shop = purchaseInvoice($branch, $product, 10);

    test()->putJson(route('shops.update', $shop), ['date' => '2026-10-05'])->assertSuccessful();

    expect($shop->inventories()->active()->first()->date)->toBe('2026-10-05')
        ->and($product->fresh()->stock)->toBe(10.0);
});
