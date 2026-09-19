<?php

use App\Http\Resources\OrderResources;
use App\Models\Order\Order;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Http\Request;

test('serie viene con la sigla del tipo de comprobante concatenada, sin exponer voucher_type_id', function () {
    $notaCreditoId = VoucherType::where('slug', VoucherTypes::VENTA_NOTA_CREDITO)->value('id');
    $order = Order::factory()->create([
        'voucher_type_id' => $notaCreditoId,
        'serie' => '001-001-000000418',
    ]);
    $order->load('voucherType');

    $array = (new OrderResources($order))->toArray(new Request);

    expect($array['atts']['serie'])->toBe('N/C 001-001-000000418')
        ->and($array['atts'])->not->toHaveKey('voucher_type_id');
});
