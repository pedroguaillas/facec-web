<?php

namespace App\Services\Order;

use App\Mail\OrderShipped;
use App\Models\Customer;
use App\Models\Order\Lot;
use App\Models\Order\Order;
use App\Services\SriSoapService;
use App\StaticClasses\VoucherStates;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class OrderSriService
{
    public function __construct(private SriSoapService $soap) {}

    public function send(Order $order): void
    {
        // Si el comprobante tiene fecha anterior al día de hoy
        // Actualizar la fecha, importante realizar aqui debido a que puede que este generado el xml con dias anteriores
        // y no se valide eso.
        $tz = config('app.timezone');
        $dateOrder = Carbon::createFromFormat('Y-m-d', $order->date, $tz);

        if (! $dateOrder->isToday()) {
            $order->date = Carbon::today($tz)->format('Y-m-d');
            $order->state = VoucherStates::SAVED;
            $order->save();

            return;
        }

        $this->soap->send(
            model: $order,
            onReceived: fn () => $this->authorize($order),
        );
    }

    public function authorize(Order $order): void
    {
        if (! is_null($order->lot_id)) {
            $lot = Lot::find($order->lot_id);

            if (in_array($lot->state, [VoucherStates::AUTHORIZED, VoucherStates::CANCELED])) {
                return;
            }
            if ($lot->state === VoucherStates::SAVED) {
                $this->sendLot($order->lot_id);

                return;
            }
            if (in_array($lot->state, [VoucherStates::SENDED, VoucherStates::RECEIVED])) {
                $this->authorizeLot($lot);

                return;
            }
        }

        $this->soap->authorize(
            model: $order,
            // authorization ya fue guardada por VoucherLifecycleService::saveAndSign()
            onAuthorized: fn () => $this->sendOrderMail($order),
        );
    }

    public function cancel(Order $order): mixed
    {
        return $this->soap->cancel($order);
    }

    /**
     * Envía copia del comprobante autorizado por correo al cliente, si el
     * usuario marcó `send_mail` al guardar y el cliente tiene email registrado.
     * Un fallo de correo se loggea pero nunca revierte ni reporta como error
     * la autorización del SRI, que ya quedó guardada antes de este callback.
     */
    private function sendOrderMail(Order $order): void
    {
        if (! $order->send_mail) {
            return;
        }

        $email = Customer::find($order->customer_id)?->email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new OrderShipped($order));
        } catch (\Throwable $e) {
            Log::error('OrderShipped mail failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─── Lotes ───────────────────────────────────────────────────────────────

    public function sendLot(int $idLot): void
    {
        $lot = Lot::find($idLot);
        $environment = (int) substr($lot->authorization, 23, 1);
        $ruc = substr($lot->authorization, 10, 13);

        $client = new \SoapClient($this->soap->receiptWsdl($environment), ['connection_timeout' => 3]);
        $params = new \stdClass;
        $params->xml = Storage::get('xmls'.DIRECTORY_SEPARATOR.$ruc.DIRECTORY_SEPARATOR.$lot->authorization.'.xml');

        try {
            $result = $client->validarComprobante($params);

            if (! property_exists($result, 'RespuestaRecepcionComprobante')) {
                return;
            }

            Order::where('lot_id', $lot->id)->update(['state' => VoucherStates::SENDED]);
            $lot->state = VoucherStates::SENDED;
            $lot->save();
            $result = $result->RespuestaRecepcionComprobante;

            switch ($result->estado) {
                case VoucherStates::RECEIVED:
                    Order::where('lot_id', $lot->id)->update(['state' => VoucherStates::RECEIVED]);
                    $lot->state = VoucherStates::RECEIVED;
                    $lot->save();
                    $this->authorizeLot($lot);
                    break;
                case VoucherStates::RETURNED:
                    $lot->extra_detail = $this->soap->parseReturnedMessage($result->comprobantes->comprobante->mensajes);
                    $lot->state = VoucherStates::RETURNED;
                    $lot->save();
                    break;
            }
        } catch (\Exception $e) {
            info('SRI sendLot error CODE: '.$e->getCode());
        }
    }

    public function authorizeLot(Lot $lot): void
    {
        if (in_array($lot->state, [VoucherStates::AUTHORIZED, VoucherStates::CANCELED])) {
            return;
        }

        $environment = (int) substr($lot->authorization, 23, 1);

        $client = new \SoapClient(
            $this->soap->authorizationWsdl($environment),
            ['soap_version' => SOAP_1_1, 'trace' => 1, 'connection_timeout' => 3, 'exceptions' => 1]
        );

        $params = ['claveAccesoLote' => $lot->authorization];

        try {
            $response = $client->autorizacionComprobanteLote($params);

            if (! property_exists($response, 'RespuestaAutorizacionLote')) {
                return;
            }

            foreach ($response->RespuestaAutorizacionLote->autorizaciones->autorizacion as $autorizacion) {
                $order = Order::where('authorization', $autorizacion->numeroAutorizacion)->first();

                $this->soap->saveAuthorizationResult(
                    model: $order,
                    autorizacion: $autorizacion,
                    onAuthorized: fn () => $this->sendOrderMail($order),
                );
            }
        } catch (\Exception $e) {
            info('SRI authorizeLot error CODE: '.$e->getCode());
        }
    }
}
