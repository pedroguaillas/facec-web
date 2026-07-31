<?php

namespace App\Xml;

class ReferralGuideBuilder extends BaseVoucherBuilder
{
    public function __construct(
        $company,
        private $guide,
        private $items,
    ) {
        parent::__construct($company);
    }

    protected function voucherTypeCode(): string
    {
        return '06';
    }

    protected function accessKeyDate(): \DateTime
    {
        return new \DateTime($this->guide->date_start);
    }

    protected function serieRaw(): string
    {
        return $this->guide->serie;
    }

    public function build(): string
    {
        $guide = $this->guide;
        $company = $this->company;

        $string = '<?xml version="1.0" encoding="UTF-8"?>';
        $string .= '<guiaRemision id="comprobante" version="1.0.0">';
        $string .= $this->infoTributaria();

        $string .= '<infoGuiaRemision>';
        $string .= "<dirPartida>{$guide->address_from}</dirPartida>";
        $string .= '<razonSocialTransportista>'.str_replace('&', 'Y', $guide->ca_name).'</razonSocialTransportista>';
        $string .= '<tipoIdentificacionTransportista>'.(strlen($guide->ca_identication) === 13 ? '04' : '05').'</tipoIdentificacionTransportista>';
        $string .= "<rucTransportista>{$guide->ca_identication}</rucTransportista>";
        $string .= '<obligadoContabilidad>'.($company->accounting ? 'SI' : 'NO').'</obligadoContabilidad>';
        $string .= '<fechaIniTransporte>'.(new \DateTime($guide->date_start))->format('d/m/Y').'</fechaIniTransporte>';
        $string .= '<fechaFinTransporte>'.(new \DateTime($guide->date_end))->format('d/m/Y').'</fechaFinTransporte>';
        $string .= "<placa>{$guide->license_plate}</placa>";
        $string .= '</infoGuiaRemision>';

        $string .= '<destinatarios>';
        $string .= '<destinatario>';
        $string .= "<identificacionDestinatario>{$guide->identication}</identificacionDestinatario>";
        $string .= '<razonSocialDestinatario>'.str_replace('&', 'Y', $guide->name).'</razonSocialDestinatario>';
        $string .= "<dirDestinatario>{$guide->address_to}</dirDestinatario>";
        $string .= $guide->reason_transfer ? "<motivoTraslado>{$guide->reason_transfer}</motivoTraslado>" : null;
        $string .= $guide->branch_destiny ? "<codEstabDestino>{$guide->branch_destiny}</codEstabDestino>" : null;
        $string .= $guide->route ? "<ruta>{$guide->route}</ruta>" : null;
        $string .= $guide->serie_invoice ? '<codDocSustento>01</codDocSustento>' : null;
        $string .= $guide->serie_invoice ? "<numDocSustento>{$guide->serie_invoice}</numDocSustento>" : null;
        $string .= $guide->authorization_invoice ? "<numAutDocSustento>{$guide->authorization_invoice}</numAutDocSustento>" : null;
        $string .= $guide->date_invoice ? '<fechaEmisionDocSustento>'.(new \DateTime($guide->date_invoice))->format('d/m/Y').'</fechaEmisionDocSustento>' : null;

        $string .= '<detalles>';
        foreach ($this->items as $item) {
            $string .= '<detalle>';
            $string .= "<codigoInterno>{$item->code}</codigoInterno>";
            $string .= "<descripcion>{$item->name}</descripcion>";
            $string .= "<cantidad>{$item->quantity}</cantidad>";
            $string .= '</detalle>';
        }
        $string .= '</detalles>';

        $string .= '</destinatario>';
        $string .= '</destinatarios>';
        $string .= '</guiaRemision>';

        return $string;
    }
}
