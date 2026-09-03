<?php

use App\Mail\RetentionShipped;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Services\Shop\Retention\RetentionXmlService;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function callSendRetentionMail(Shop $shop): void
{
    $method = new ReflectionMethod(RetentionXmlService::class, 'sendRetentionMail');
    $method->invoke(app(RetentionXmlService::class), $shop);
}

test('sendRetentionMail no intenta enviar ni loggea si el proveedor no tiene correo', function () {
    Mail::fake();
    Log::spy();

    $provider = Provider::factory()->create(['email' => null]);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'state_retencion' => VoucherStates::AUTHORIZED]);

    callSendRetentionMail($shop);

    Mail::assertNothingSent();
    Log::shouldNotHaveReceived('error');
    expect($shop->fresh()->send_mail_retention)->toBeFalsy();
});

test('sendRetentionMail envía el correo si el proveedor sí tiene correo registrado', function () {
    Mail::fake();

    $provider = Provider::factory()->create(['email' => 'proveedor@example.com']);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'state_retencion' => VoucherStates::AUTHORIZED]);

    callSendRetentionMail($shop);

    Mail::assertSent(RetentionShipped::class);
    expect($shop->fresh()->send_mail_retention)->toBeTruthy();
});

test('resendMail lanza excepción si la retención no está autorizada', function () {
    Mail::fake();

    $provider = Provider::factory()->create(['email' => 'proveedor@example.com']);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'state_retencion' => VoucherStates::SIGNED]);

    app(RetentionXmlService::class)->resendMail($shop);
})->throws(RuntimeException::class, 'La retención debe estar autorizada para poder enviar el correo.');

test('resendMail lanza excepción si el proveedor no tiene correo', function () {
    Mail::fake();

    $provider = Provider::factory()->create(['email' => null]);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'state_retencion' => VoucherStates::AUTHORIZED]);

    app(RetentionXmlService::class)->resendMail($shop);
})->throws(RuntimeException::class, 'El proveedor no tiene correo electrónico registrado.');

test('resendMail envía el correo y marca send_mail_retention cuando está autorizada y el proveedor tiene correo', function () {
    Mail::fake();

    $provider = Provider::factory()->create(['email' => 'proveedor@example.com']);
    $shop = Shop::factory()->create(['provider_id' => $provider->id, 'state_retencion' => VoucherStates::AUTHORIZED]);

    app(RetentionXmlService::class)->resendMail($shop);

    Mail::assertSent(RetentionShipped::class);
    expect($shop->fresh()->send_mail_retention)->toBeTruthy();
});
