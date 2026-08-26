<?php

use App\Mail\OrderShipped;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Services\Order\OrderSriService;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function callSendOrderMail(Order $order): void
{
    $method = new ReflectionMethod(OrderSriService::class, 'sendOrderMail');
    $method->invoke(app(OrderSriService::class), $order);
}

test('sendOrderMail no intenta enviar ni loggea si el cliente no tiene correo', function () {
    Mail::fake();
    Log::spy();

    $customer = Customer::factory()->create(['email' => null]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'state' => VoucherStates::AUTHORIZED]);

    callSendOrderMail($order);

    Mail::assertNothingSent();
    Log::shouldNotHaveReceived('error');
    expect($order->fresh()->send_mail)->toBeFalsy();
});

test('sendOrderMail envía el correo si el cliente sí tiene correo registrado', function () {
    Mail::fake();

    $customer = Customer::factory()->create(['email' => 'cliente@example.com']);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'state' => VoucherStates::AUTHORIZED]);

    callSendOrderMail($order);

    Mail::assertSent(OrderShipped::class);
    expect($order->fresh()->send_mail)->toBeTruthy();
});
