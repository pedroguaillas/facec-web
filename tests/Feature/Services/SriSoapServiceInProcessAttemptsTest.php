<?php

use App\Models\Order\Order;
use App\Services\SriSoapService;
use App\StaticClasses\VoucherStates;

/**
 * SoapClient real usa __call mágico; para simular respuestas del WS de Autorización
 * sin pegarle a la red, se subclasea sin llamar al constructor real y se sobreescribe
 * el método esperado directamente.
 */
class FakeAuthorizationSoapClient extends SoapClient
{
    public function __construct(private mixed $response) {}

    public function autorizacionComprobante($params)
    {
        return $this->response;
    }
}

function makeSignedOrder(array $attributes = []): Order
{
    return Order::factory()->create(array_merge([
        'state' => VoucherStates::SIGNED,
        'xml' => 'xmls/1234567890001/2026/08/FIRMADO/0211202401050306179800120010020000000677300995216.xml',
    ], $attributes));
}

function enProcesoResponse(): object
{
    return (object) [
        'RespuestaAutorizacionComprobante' => (object) [
            'autorizaciones' => (object) [
                'autorizacion' => (object) ['estado' => 'EN PROCESO'],
            ],
        ],
    ];
}

function serviceWithFakeAuthorization(mixed $response): SriSoapService
{
    $service = Mockery::mock(SriSoapService::class.'[authorizationClient]')->makePartial();
    $service->shouldReceive('authorizationClient')->andReturn(new FakeAuthorizationSoapClient($response));

    return $service;
}

test('EN PROCESO incrementa el contador y mantiene el estado en IN_PROCESS mientras no llegue al umbral', function () {
    $order = makeSignedOrder(['in_process_attempts' => 0]);
    $service = serviceWithFakeAuthorization(enProcesoResponse());

    $service->authorize($order, inProcessAttemptsField: 'in_process_attempts');

    expect($order->fresh())
        ->state->toBe(VoucherStates::IN_PROCESS)
        ->in_process_attempts->toBe(1);
});

test('la 3ra consulta seguida en EN PROCESO resetea el contador y fuerza SIGNED para reenviar', function () {
    $order = makeSignedOrder(['state' => VoucherStates::IN_PROCESS, 'in_process_attempts' => 2]);
    $service = serviceWithFakeAuthorization(enProcesoResponse());

    $service->authorize($order, inProcessAttemptsField: 'in_process_attempts');

    expect($order->fresh())
        ->state->toBe(VoucherStates::SIGNED)
        ->in_process_attempts->toBe(0);
});

test('sin inProcessAttemptsField, EN PROCESO solo actualiza el estado (comportamiento previo)', function () {
    $order = makeSignedOrder(['in_process_attempts' => 0]);
    $service = serviceWithFakeAuthorization(enProcesoResponse());

    $service->authorize($order);

    expect($order->fresh())
        ->state->toBe(VoucherStates::IN_PROCESS)
        ->in_process_attempts->toBe(0);
});
