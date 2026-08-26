<?php

namespace App\Services;

use App\StaticClasses\VoucherStates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SriSoapService
{
    private const RECEIPT_CERT = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl';

    private const RECEIPT_PROD = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl';

    private const AUTH_CERT = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl';

    private const AUTH_PROD = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl';

    private const CONSULTA_CERT = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/ConsultaComprobante?wsdl';

    private const CONSULTA_PROD = 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/ConsultaComprobante?wsdl';

    // ─── WSDL resolution ──────────────────────────────────────────────────────

    public function receiptWsdl(int $environment): string
    {
        return $environment === 1 ? self::RECEIPT_CERT : self::RECEIPT_PROD;
    }

    public function authorizationWsdl(int $environment): string
    {
        return $environment === 1 ? self::AUTH_CERT : self::AUTH_PROD;
    }

    public function consultaWsdl(int $environment): string
    {
        return $environment === 1 ? self::CONSULTA_CERT : self::CONSULTA_PROD;
    }

    // ─── Path helpers ─────────────────────────────────────────────────────────

    /** Extrae el tipo de ambiente (1 = certificación, 2 = producción) del path del XML firmado */
    public function extractEnvironmentFromXml(string $xmlPath): int
    {
        return (int) substr($xmlPath, -30, 1);
    }

    /** Extrae la clave de acceso del path del XML firmado */
    public function extractAccessKey(string $xmlPath): string
    {
        return substr(substr($xmlPath, -53), 0, 49);
    }

    // ─── SoapClient factories ─────────────────────────────────────────────────

    public function receiptClient(string $xmlPath): \SoapClient
    {
        return new \SoapClient(
            $this->receiptWsdl($this->extractEnvironmentFromXml($xmlPath)),
            ['connection_timeout' => 3]
        );
    }

    public function authorizationClient(string $xmlPath): \SoapClient
    {
        return new \SoapClient(
            $this->authorizationWsdl($this->extractEnvironmentFromXml($xmlPath)),
            ['soap_version' => SOAP_1_1, 'trace' => 1, 'connection_timeout' => 3, 'exceptions' => 0]
        );
    }

    public function consultaClient(string $xmlPath): \SoapClient
    {
        return new \SoapClient(
            $this->consultaWsdl($this->extractEnvironmentFromXml($xmlPath)),
            ['soap_version' => SOAP_1_1, 'trace' => 1, 'connection_timeout' => 3, 'exceptions' => 0]
        );
    }

    // ─── XML builder ─────────────────────────────────────────────────────────

    public function buildAuthorizedXml(object $comprobante): string
    {
        $dom = new \DOMDocument('1.0', 'ISO-8859-1');
        $autorizacion = $dom->createElement('autorizacion');
        $dom->appendChild($autorizacion);

        $autorizacion->appendChild($dom->createElement('estado', $comprobante->estado));
        $autorizacion->appendChild($dom->createElement('numeroAutorizacion', $comprobante->numeroAutorizacion));
        $autorizacion->appendChild($dom->createElement('fechaAutorizacion', $comprobante->fechaAutorizacion));
        $autorizacion->appendChild($dom->createElement('ambiente', $comprobante->ambiente));

        $elementocomprobante = $dom->createElement('comprobante');
        $autorizacion->appendChild($elementocomprobante);
        $elementocomprobante->appendChild($dom->createCDATASection($comprobante->comprobante));

        return $dom->saveXML();
    }

    // ─── Message parser ───────────────────────────────────────────────────────

    public function parseReturnedMessage(object $mensajes): string
    {
        $mensajes = json_decode(json_encode($mensajes), true);
        $message = $mensajes['mensaje']['mensaje'].'.';

        if (array_key_exists('informacionAdicional', $mensajes['mensaje'])) {
            $message .= ' informacionAdicional : '.$mensajes['mensaje']['informacionAdicional'];
        }

        $message = substr($message, 0, 255);

        return $message;
    }

    // ─── Core operations ─────────────────────────────────────────────────────

    /**
     * Envía un comprobante al SRI y actualiza el estado del modelo.
     *
     * @param  callable  $onReceived  Callback invocado cuando el SRI devuelve RECIBIDA (normalmente llama a authorize)
     */
    public function send(
        Model $model,
        callable $onReceived,
        string $xmlField = 'xml',
        string $stateField = 'state',
        string $extraDetailField = 'extra_detail',
    ): void {
        $xmlPath = $model->{$xmlField};
        $client = $this->receiptClient($xmlPath);
        $params = new \stdClass;
        $params->xml = Storage::get($xmlPath);

        try {
            $result = $client->validarComprobante($params);

            if (! property_exists($result, 'RespuestaRecepcionComprobante')) {
                return;
            }

            $model->{$stateField} = VoucherStates::SENDED;
            $model->save();
            $result = $result->RespuestaRecepcionComprobante;

            switch ($result->estado) {
                case VoucherStates::RECEIVED:
                    $model->{$stateField} = VoucherStates::RECEIVED;
                    $model->save();
                    ($onReceived)();
                    break;
                case VoucherStates::RETURNED:
                    $model->{$extraDetailField} = $this->parseReturnedMessage($result->comprobantes->comprobante->mensajes);
                    $model->{$stateField} = VoucherStates::RETURNED;
                    $model->save();
                    break;
            }
        } catch (\Exception $e) {
            info('SRI send error CODE: '.$e->getCode());
        }
    }

    /**
     * Consulta la autorización de un comprobante individual al SRI.
     *
     * @param  string|null  $authorizationField  Si se pasa, guarda el numeroAutorizacion del SRI en este campo.
     *                                           Para Order es null (ya se guardó la clave en lifecycle).
     * @param  callable|null  $onAuthorized  Callback invocado cuando el SRI autoriza (ej. enviar mail).
     */
    public function authorize(
        Model $model,
        string $xmlField = 'xml',
        string $stateField = 'state',
        string $authorizedField = 'autorized',
        string $extraDetailField = 'extra_detail',
        ?string $authorizationField = 'authorization',
        ?callable $onAuthorized = null,
    ): void {
        if (in_array($model->{$stateField}, [VoucherStates::AUTHORIZED, VoucherStates::CANCELED])) {
            return;
        }

        $xmlPath = $model->{$xmlField};
        $client = $this->authorizationClient($xmlPath);
        $params = ['claveAccesoComprobante' => $this->extractAccessKey($xmlPath)];

        try {
            $response = $client->autorizacionComprobante($params);

            if (! property_exists($response, 'RespuestaAutorizacionComprobante')) {
                return;
            }

            $autorizacion = $response->RespuestaAutorizacionComprobante->autorizaciones->autorizacion;

            $this->saveAuthorizationResult(
                model: $model,
                xmlField: $xmlField,
                stateField: $stateField,
                authorizedField: $authorizedField,
                extraDetailField: $extraDetailField,
                authorizationField: $authorizationField,
                autorizacion: $autorizacion,
                onAuthorized: $onAuthorized,
            );
        } catch (\Exception $e) {
            info('SRI authorize error CODE: '.$e->getCode());
        }
    }

    /**
     * Aplica el resultado de autorización del SRI sobre el modelo.
     * Usado tanto en authorize() individual como en authorizeLot() de SriOrderService.
     */
    public function saveAuthorizationResult(
        Model $model,
        object $autorizacion,
        string $xmlField = 'xml',
        string $stateField = 'state',
        string $authorizedField = 'autorized',
        string $extraDetailField = 'extra_detail',
        ?string $authorizationField = 'authorization',
        ?callable $onAuthorized = null,
    ): void {
        switch ($autorizacion->estado) {
            case VoucherStates::AUTHORIZED:
                $xmlPath = $model->{$xmlField};
                $toPath = str_replace(VoucherStates::SIGNED, VoucherStates::AUTHORIZED, $xmlPath);
                $folder = substr($toPath, 0, strpos($toPath, VoucherStates::AUTHORIZED)).VoucherStates::AUTHORIZED;

                if (! Storage::exists($folder)) {
                    Storage::makeDirectory($folder);
                }

                Storage::put($toPath, $this->buildAuthorizedXml($autorizacion));
                Storage::delete($xmlPath);

                $authorizationDate = \DateTime::createFromFormat('Y-m-d\TH:i:sP', $autorizacion->fechaAutorizacion);

                $model->{$xmlField} = $toPath;
                $model->{$stateField} = VoucherStates::AUTHORIZED;
                $model->{$authorizedField} = $authorizationDate->format('Y-m-d H:i:s');

                if ($authorizationField) {
                    $model->{$authorizationField} = $autorizacion->numeroAutorizacion;
                }

                $model->save();

                if ($onAuthorized) {
                    ($onAuthorized)();
                }
                break;

            case VoucherStates::REJECTED:
                $model->{$stateField} = VoucherStates::REJECTED;
                $model->{$extraDetailField} = $this->parseReturnedMessage($autorizacion->mensajes);
                $model->save();
                break;

            default:
                $model->{$stateField} = VoucherStates::IN_PROCESS;
                $model->save();
                break;
        }
    }

    /**
     * Consulta el estado de anulación de un comprobante autorizado en el SRI
     * (WS ConsultaComprobante, distinto del usado para autorizar) y persiste
     * el resultado si corresponde.
     *
     * @return array{status: string|null, canceled: bool} `status` es el valor crudo de
     *                                                    `estadoAutorizacion` (AUTORIZADO, NO AUTORIZADO,
     *                                                    PENDIENTE DE ANULAR, ANULADO) o null si no se pudo
     *                                                    determinar (error de conexión o consulta RECHAZADA).
     */
    public function cancel(Model $model, string $xmlField = 'xml', string $stateField = 'state'): array
    {
        if ($model->{$stateField} !== VoucherStates::AUTHORIZED) {
            return ['status' => $model->{$stateField}, 'canceled' => false];
        }

        $xmlPath = $model->{$xmlField};
        $client = $this->consultaClient($xmlPath);
        $params = ['claveAcceso' => $this->extractAccessKey($xmlPath)];

        try {
            $response = $client->consultarEstadoAutorizacionComprobante($params);
        } catch (\Throwable $e) {
            info('SRI cancel error: '.$e->getMessage());

            return ['status' => null, 'canceled' => false];
        }

        if (! property_exists($response, 'EstadoAutorizacionComprobante')) {
            return ['status' => null, 'canceled' => false];
        }

        $estado = $response->EstadoAutorizacionComprobante;

        // 'RECHAZADA' es un error de la consulta en sí (clave inexistente, fecha fuera de rango),
        // no un estado del comprobante — no se persiste como tal.
        if (($estado->estadoConsulta ?? null) === 'RECHAZADA') {
            info('SRI cancel query rejected: '.($estado->mensajes->mensaje->informacionAdicional ?? $estado->mensajes->mensaje->mensaje ?? 'sin detalle'));

            return ['status' => null, 'canceled' => false];
        }

        $status = $estado->estadoAutorizacion ?? null;

        if (in_array($status, [VoucherStates::CANCELED, VoucherStates::PENDING_CANCELATION], true)) {
            $model->{$stateField} = $status;
            $model->save();
        }

        return ['status' => $status, 'canceled' => $status === VoucherStates::CANCELED];
    }
}
