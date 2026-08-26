<?php

use App\Http\Resources\ShopResources;
use App\Models\Shop\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
