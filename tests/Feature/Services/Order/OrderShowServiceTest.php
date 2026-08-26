<?php

use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Services\Order\OrderShowService;

test('getOrderDetail no incluye el RUC Proveedor fijo entre los aditionals devueltos al frontend', function () {
    $order = Order::factory()->create();
    OrderAditional::create(['order_id' => $order->id, ...Order::REQUIRED_ADITIONAL]);
    OrderAditional::create(['order_id' => $order->id, 'name' => 'Guía', 'description' => '001-001-123456789']);

    $detail = app(OrderShowService::class)->getOrderDetail($order);

    expect($detail['order_aditionals'])->toHaveCount(1)
        ->and($detail['order_aditionals']->first()->name)->toBe('Guía');
});
