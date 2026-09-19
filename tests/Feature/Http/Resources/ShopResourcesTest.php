<?php

use App\Http\Resources\ShopResources;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

test('serie viene con la sigla del tipo de comprobante concatenada, sin exponer voucher_type_id', function () {
    $liquidacionId = VoucherType::where('slug', VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->value('id');
    $shop = Shop::factory()->create([
        'voucher_type_id' => $liquidacionId,
        'serie' => '001-001-000000099',
        'serie_retencion' => '001-001-000000005',
    ]);
    $shop->load('voucherType');

    $array = (new ShopResources($shop))->toArray(new Request);

    expect($array['atts']['serie'])->toBe('L/C 001-001-000000099')
        ->and($array['atts']['serie_retencion'])->toBe('RET 001-001-000000005')
        ->and($array['atts'])->not->toHaveKey('voucher_type_id');
});

test('serie_retencion null (sin retencion aplicada) no se le pega la sigla RET', function () {
    $facturaId = VoucherType::where('slug', VoucherTypes::COMPRA_FACTURA)->value('id');
    $shop = Shop::factory()->create(['voucher_type_id' => $facturaId, 'serie_retencion' => null]);
    $shop->load('voucherType');

    $array = (new ShopResources($shop))->toArray(new Request);

    expect($array['atts']['serie_retencion'])->toBeNull()
        ->and($array['atts']['serie'])->toBe("FAC {$shop->serie}");
});

test('retention es la suma de shop_retention_items.value, nunca null', function () {
    DB::table('taxes')->insert(['code' => 'TEST01', 'conception' => 'Test', 'porcentage' => 10, 'type' => 'renta']);
    $shop = Shop::factory()->create();
    $shop->shopretentionitems()->create(['code' => 1, 'tax_code' => 'TEST01', 'base' => 100, 'porcentage' => 10, 'value' => 10]);
    $shop->shopretentionitems()->create(['code' => 2, 'tax_code' => 'TEST01', 'base' => 100, 'porcentage' => 6.25, 'value' => 6.25]);

    $withSum = Shop::withSum('shopretentionitems as retention_sum', 'value')->find($shop->id);
    $array = (new ShopResources($withSum))->toArray(new Request);

    expect($array['atts']['retention'])->not->toBeNull();
    expect((float) $array['atts']['retention'])->toBe(16.25);
});

test('retention es 0 (no null) cuando no hay retenciones cargadas', function () {
    $shop = Shop::factory()->create();

    $withSum = Shop::withSum('shopretentionitems as retention_sum', 'value')->find($shop->id);
    $array = (new ShopResources($withSum))->toArray(new Request);

    expect($array['atts']['retention'])->not->toBeNull();
    expect((float) $array['atts']['retention'])->toBe(0.0);
});
