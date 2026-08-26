<?php

use App\Models\Order\Order;
use App\Services\SriSoapService;
use App\StaticClasses\VoucherStates;

/**
 * SoapClient real usa __call mágico; para simular respuestas del WS de ConsultaComprobante
 * sin pegarle a la red, se subclasea sin llamar al constructor real y se sobreescribe
 * el método esperado directamente.
 */
class FakeConsultaSoapClient extends SoapClient
{
    public function __construct(private mixed $response) {}

    public function consultarEstadoAutorizacionComprobante($params)
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

function serviceWithFakeConsulta(mixed $response): SriSoapService
{
    $service = Mockery::mock(SriSoapService::class.'[consultaClient]')->makePartial();
    $service->shouldReceive('consultaClient')->andReturn(new FakeConsultaSoapClient($response));

    return $service;
}

test('cancel persiste ANULADO cuando el SRI confirma la anulación', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeConsulta((object) [
        'EstadoAutorizacionComprobante' => (object) [
            'estadoAutorizacion' => 'ANULADO',
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => 'ANULADO', 'canceled' => true]);
    expect($order->fresh()->state)->toBe(VoucherStates::CANCELED);
});

test('cancel persiste PENDIENTE DE ANULAR sin marcarlo como cancelado', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeConsulta((object) [
        'EstadoAutorizacionComprobante' => (object) [
            'estadoAutorizacion' => 'PENDIENTE DE ANULAR',
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => 'PENDIENTE DE ANULAR', 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::PENDING_CANCELATION);
});

test('cancel no persiste nada cuando el SRI responde RECHAZADA (error de consulta, no del comprobante)', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeConsulta((object) [
        'EstadoAutorizacionComprobante' => (object) [
            'estadoConsulta' => 'RECHAZADA',
            'mensajes' => (object) [
                'mensaje' => (object) ['mensaje' => 'ERROR AL CONSULTAR DATOS DEL SERVICIO WEB'],
            ],
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => null, 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel no toca el modelo si el comprobante sigue AUTORIZADO', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeConsulta((object) [
        'EstadoAutorizacionComprobante' => (object) [
            'estadoAutorizacion' => 'AUTORIZADO',
        ],
    ]);

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => 'AUTORIZADO', 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel devuelve status null si el SOAP client lanza una excepción', function () {
    $order = makeAuthorizedOrder();

    $service = serviceWithFakeConsulta(new SoapFault('Client', 'timeout'));

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => null, 'canceled' => false]);
    expect($order->fresh()->state)->toBe(VoucherStates::AUTHORIZED);
});

test('cancel no consulta al SRI si el comprobante no está AUTORIZADO', function () {
    $order = Order::factory()->create(['state' => VoucherStates::SAVED]);

    $service = Mockery::mock(SriSoapService::class.'[consultaClient]')->makePartial();
    $service->shouldNotReceive('consultaClient');

    $result = $service->cancel($order);

    expect($result)->toBe(['status' => VoucherStates::SAVED, 'canceled' => false]);
});
