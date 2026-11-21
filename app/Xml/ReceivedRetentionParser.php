<?php

namespace App\Xml;

use RuntimeException;
use SimpleXMLElement;

/**
 * Lee el XML de un comprobante de retención que un cliente emitió sobre una
 * venta. Acepta el XML autorizado (envoltorio <autorizacion> con el
 * comprobante en CDATA, tal como lo descarga el SRI o lo envía el cliente) o
 * el <comprobanteRetencion> suelto, en versión 1.0.0 (<impuestos>) o 2.0.0
 * (<docsSustento>/<retenciones>).
 */
class ReceivedRetentionParser
{
    /** Códigos de impuesto retenido que maneja la venta: 1 = renta, 2 = IVA. */
    public const RENTA = 1;

    public const IVA = 2;

    /**
     * @return array{
     *     serie: string,
     *     date: string,
     *     authorization: string,
     *     issuer_ruc: string,
     *     issuer_name: string,
     *     subject_identification: string,
     *     items: array<int, array{code: int, tax_code: string, base: float, porcentage: float, value: float, doc_sustento: ?string}>
     * }
     *
     * @throws RuntimeException
     */
    public function parse(string $content): array
    {
        $root = $this->load($content);
        $authorization = null;

        if ($root->getName() !== 'comprobanteRetencion') {
            $autorizacion = $root->getName() === 'autorizacion' ? $root : ($root->xpath('//autorizacion')[0] ?? null);

            if ($autorizacion === null || ! isset($autorizacion->comprobante)) {
                throw new RuntimeException('El archivo no es un comprobante de retención.');
            }

            if (isset($autorizacion->estado) && strtoupper(trim((string) $autorizacion->estado)) !== 'AUTORIZADO') {
                throw new RuntimeException('El comprobante de retención no está autorizado por el SRI.');
            }

            $authorization = trim((string) $autorizacion->numeroAutorizacion) ?: null;
            $root = $this->load(trim((string) $autorizacion->comprobante));

            if ($root->getName() !== 'comprobanteRetencion') {
                throw new RuntimeException('El archivo no es un comprobante de retención.');
            }
        }

        $info = $root->infoTributaria;
        $fechaEmision = \DateTime::createFromFormat('d/m/Y', trim((string) $root->infoCompRetencion->fechaEmision));

        if ($fechaEmision === false) {
            throw new RuntimeException('La fecha de emisión de la retención no es válida.');
        }

        $items = isset($root->docsSustento) ? $this->itemsV2($root) : $this->itemsV1($root);

        if ($items === []) {
            throw new RuntimeException('La retención no tiene impuestos retenidos.');
        }

        return [
            'serie' => sprintf('%s-%s-%s', trim((string) $info->estab), trim((string) $info->ptoEmi), trim((string) $info->secuencial)),
            'date' => $fechaEmision->format('Y-m-d'),
            'authorization' => $authorization ?? trim((string) $info->claveAcceso),
            'issuer_ruc' => trim((string) $info->ruc),
            'issuer_name' => trim((string) $info->razonSocial),
            'subject_identification' => trim((string) $root->infoCompRetencion->identificacionSujetoRetenido),
            'items' => $items,
        ];
    }

    private function load(string $content): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // Sin LIBXML_NOENT ni carga de DTD: no se resuelven entidades externas.
        $xml = simplexml_load_string(trim($content), SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('El archivo no es un XML válido.');
        }

        return $xml;
    }

    /**
     * @return array<int, array{code: int, tax_code: string, base: float, porcentage: float, value: float, doc_sustento: ?string}>
     */
    private function itemsV2(SimpleXMLElement $root): array
    {
        $items = [];

        foreach ($root->docsSustento->docSustento as $docSustento) {
            foreach ($docSustento->retenciones->retencion ?? [] as $retencion) {
                $items[] = $this->item($retencion, trim((string) $docSustento->numDocSustento) ?: null);
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{code: int, tax_code: string, base: float, porcentage: float, value: float, doc_sustento: ?string}>
     */
    private function itemsV1(SimpleXMLElement $root): array
    {
        $items = [];

        foreach ($root->impuestos->impuesto ?? [] as $impuesto) {
            $items[] = $this->item($impuesto, trim((string) $impuesto->numDocSustento) ?: null);
        }

        return $items;
    }

    /**
     * @return array{code: int, tax_code: string, base: float, porcentage: float, value: float, doc_sustento: ?string}
     */
    private function item(SimpleXMLElement $node, ?string $docSustento): array
    {
        return [
            'code' => (int) $node->codigo,
            'tax_code' => trim((string) $node->codigoRetencion),
            'base' => round((float) $node->baseImponible, 2),
            'porcentage' => round((float) $node->porcentajeRetener, 2),
            'value' => round((float) $node->valorRetenido, 2),
            'doc_sustento' => $docSustento,
        ];
    }
}
