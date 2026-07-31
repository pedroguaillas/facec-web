<?php

namespace App\Xml;

class RetentionBuilder extends BaseVoucherBuilder
{
    public function __construct(
        $company,
        private $shop,
    ) {
        parent::__construct($company);
    }

    protected function voucherTypeCode(): string
    {
        return '07';
    }

    protected function accessKeyDate(): \DateTime
    {
        return new \DateTime($this->shop->date_retention);
    }

    protected function serieRaw(): string
    {
        return $this->shop->serie_retencion;
    }

    public function build(): string
    {
        $shop = $this->shop;
        $company = $this->company;

        $typeId = match ($shop->type_identification) {
            'ruc' => '04',
            'cédula' => '05',
            'pasaporte' => '06',
        };

        $date = new \DateTime($shop->date);

        $string = '<?xml version="1.0" encoding="UTF-8"?>';
        $string .= '<comprobanteRetencion id="comprobante" version="2.0.0">';
        $string .= $this->infoTributaria();

        $string .= '<infoCompRetencion>';
        $string .= '<fechaEmision>'.(new \DateTime($shop->date_retention))->format('d/m/Y').'</fechaEmision>';
        $string .= '<obligadoContabilidad>'.($company->accounting ? 'SI' : 'NO').'</obligadoContabilidad>';
        $string .= "<tipoIdentificacionSujetoRetenido>{$typeId}</tipoIdentificacionSujetoRetenido>";
        $string .= $typeId === '06' ? '<tipoSujetoRetenido>01</tipoSujetoRetenido>' : null;  // fix: era === 'pasaporte' (bug)
        $string .= '<parteRel>NO</parteRel>';
        $string .= '<razonSocialSujetoRetenido>'.str_replace('&', 'Y', $shop->name).'</razonSocialSujetoRetenido>';
        $string .= "<identificacionSujetoRetenido>{$shop->identication}</identificacionSujetoRetenido>";
        $string .= '<periodoFiscal>'.$date->format('m/Y').'</periodoFiscal>';
        $string .= '</infoCompRetencion>';

        $string .= '<docsSustento>';
        $string .= '<docSustento>';
        $string .= '<codSustento>'.((int) $shop->iva > 0 ? '01' : '07').'</codSustento>';
        $string .= '<codDocSustento>'.str_pad($shop->voucher_type, 2, '0', STR_PAD_LEFT).'</codDocSustento>';
        $string .= '<numDocSustento>'.str_replace('-', '', $shop->serie).'</numDocSustento>';
        $string .= '<fechaEmisionDocSustento>'.$date->format('d/m/Y').'</fechaEmisionDocSustento>';
        $string .= '<pagoLocExt>01</pagoLocExt>';
        $string .= '<totalSinImpuestos>0.00</totalSinImpuestos>';
        $string .= "<importeTotal>{$shop->total}</importeTotal>";

        $string .= '<impuestosDocSustento>';
        $string .= '<impuestoDocSustento>';
        $string .= '<codImpuestoDocSustento>2</codImpuestoDocSustento>';
        $string .= '<codigoPorcentaje>0</codigoPorcentaje>';
        $string .= '<baseImponible>0.00</baseImponible>';
        $string .= '<tarifa>0.00</tarifa>';
        $string .= '<valorImpuesto>0.00</valorImpuesto>';
        $string .= '</impuestoDocSustento>';
        $string .= '</impuestosDocSustento>';

        $string .= '<retenciones>';
        foreach ($shop->shopretentionitems as $item) {
            $string .= '<retencion>';
            $string .= "<codigo>{$item->code}</codigo>";
            $string .= "<codigoRetencion>{$item->tax_code}</codigoRetencion>";
            $string .= "<baseImponible>{$item->base}</baseImponible>";
            $string .= "<porcentajeRetener>{$item->porcentage}</porcentajeRetener>";
            $string .= "<valorRetenido>{$item->value}</valorRetenido>";
            $string .= '</retencion>';
        }
        $string .= '</retenciones>';

        $string .= '<pagos>';
        $string .= '<pago>';
        $string .= '<formaPago>20</formaPago>';
        $string .= "<total>{$shop->total}</total>";
        $string .= '</pago>';
        $string .= '</pagos>';

        $string .= '</docSustento>';
        $string .= '</docsSustento>';
        $string .= '</comprobanteRetencion>';

        return $string;
    }
}
