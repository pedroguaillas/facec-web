<?php

use App\Models\Order\Order;
use App\Services\SriSoapService;
use App\StaticClasses\VoucherStates;

/**
 * SoapClient real usa __call mágico; para simular respuestas del WS
 * AutorizacionComprobantesOffline sin pegarle a la red, se subclasea sin
 * llamar al constructor real y se sobreescribe el método esperado directamente.
 */
class FakeAuthorizationSoapClient extends SoapClient
{
    public function __construct(private mixed $response) {}

    public function autorizacionComprobante($params)
    {
        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

function makeAuthorizedOrder(): Order
{
    return Order::factory()->create([
        'state' => VoucherStates::AUTHORIZED,
        'xml' => 'xmls/1234567890001/2026/08/AUTORIZADO/0211202401050306179800120010020000000677300995216.xml',
    ]);
}

function serviceWithFakeAuthorization(mixed $response): SriSoapService
{
    $service = Mockery::mock(SriSoapService::class.'[authorizationClient]')->makePartial();
    $service->shouldReceive('authorizationClient')->andReturn(new FakeAuthorizationSoapClient($response));

    return $service;
}

test('cancel persiste ANULADO cuando el SRI ya no devuelve el comprobante', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeAuthorization((object) [
        'RespuestaAutorizacionComprobante' => (object) [
            'numeroComprobantes' => '0',
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => VoucherStates::CANCELED, 'canceled' => true]);
    expect($order->fresh()->state)->toBe(VoucherStates::CANCELED);
});

test('cancel no persiste nada si el comprobante sigue vigente en el SRI', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeAuthorization((object) [
        'RespuestaAutorizacionComprobante' => (object) [
            'numeroComprobantes' => '1',
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => VoucherStates::AUTHORIZED, 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel devuelve status null si la respuesta no trae RespuestaAutorizacionComprobante', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeAuthorization((object) []);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => null, 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel devuelve status null si el SOAP client lanza una excepción', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeAuthorization(new SoapFault('Client', 'timeout'));

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => null, 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel no consulta al SRI si el comprobante no está AUTORIZADO', function () {
    $order = Order::factory()->create(['state' => VoucherStates::SAVED]);

    $service = Mockery::mock(SriSoapService::class.'[authorizationClient]')->makePartial();
    $service->shouldNotReceive('authorizationClient');

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => VoucherStates::SAVED, 'canceled' => false]);
});
