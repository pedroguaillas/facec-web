<?php

use App\Mail\ShopLcShipped;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Services\Shop\ShopLcXmlService;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function callSendShopMail(Shop $shop): void
{
    $method = new ReflectionMethod(ShopLcXmlService::class, 'sendShopMail');
    $method->invoke(app(ShopLcXmlService::class), $shop);
}

test('sendShopMail no intenta enviar ni loggea si el proveedor no tiene correo', function () {
    Mail::fake();
    Log::spy();

    $provider = Provider::factory()->create(['email' => null]);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'voucher_type' => 3, 'state' => VoucherStates::AUTHORIZED]);

    callSendShopMail($shop);

    Mail::assertNothingSent();
    Log::shouldNotHaveReceived('error');
    expect($shop->fresh()->send_mail_set_purchase)->toBeFalsy();
});

test('sendShopMail envía el correo si el proveedor sí tiene correo registrado', function () {
    Mail::fake();

    $provider = Provider::factory()->create(['email' => 'proveedor@example.com']);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'voucher_type' => 3, 'state' => VoucherStates::AUTHORIZED]);

    callSendShopMail($shop);

    Mail::assertSent(ShopLcShipped::class);
    expect($shop->fresh()->send_mail_set_purchase)->toBeTruthy();
});
