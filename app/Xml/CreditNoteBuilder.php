<?php

namespace App\Xml;

use App\Xml\Concerns\HasItemTaxes;

class CreditNoteBuilder extends BaseVoucherBuilder
{
    use HasItemTaxes;

    public function __construct(
        $company,
        private $order,
        private $items,
    ) {
        parent::__construct($company);
    }

    protected function voucherTypeCode(): string
    {
        return str_pad($this->order->voucher_type, 2, '0', STR_PAD_LEFT);
    }

    protected function accessKeyDate(): \DateTime
    {
        return new \DateTime($this->order->date);
    }

    protected function serieRaw(): string
    {
        return $this->order->serie;
    }

    public function build(): string
    {
        $order = $this->order;
        $company = $this->company;
        $version = $company->decimal > 2 ? 1 : 0;

        $typeId = match ($order->type_identification) {
            'ruc' => '04',
            'cédula' => '05',
            'pasaporte' => '06',
        };

        $string = '<?xml version="1.0" encoding="UTF-8"?>';
        $string .= "<notaCredito id=\"comprobante\" version=\"1.{$version}.0\">";
        $string .= $this->infoTributaria();

        $string .= '<infoNotaCredito>';

        $date = new \DateTime($order->date);
        $string .= '<fechaEmision>'.$date->format('d/m/Y').'</fechaEmision>';
        $string .= "<tipoIdentificacionComprador>{$typeId}</tipoIdentificacionComprador>";
        $string .= '<razonSocialComprador>'.str_replace('&', 'Y', $order->name).'</razonSocialComprador>';
        $string .= "<identificacionComprador>{$order->identication}</identificacionComprador>";
        $string .= '<obligadoContabilidad>'.($company->accounting ? 'SI' : 'NO').'</obligadoContabilidad>';

        $string .= '<codDocModificado>01</codDocModificado>';
        $string .= "<numDocModificado>{$order->serie_order}</numDocModificado>";
        $string .= '<fechaEmisionDocSustento>'.(new \DateTime($order->date_order))->format('d/m/Y').'</fechaEmisionDocSustento>';
        $string .= "<totalSinImpuestos>{$order->sub_total}</totalSinImpuestos>";
        $string .= "<valorModificacion>{$order->total}</valorModificacion>";

        $string .= '<moneda>DOLAR</moneda>';

        $string .= '<totalConImpuestos>';
        foreach ($this->groupTaxes($this->items) as $tax) {
            $string .= '<totalImpuesto>';
            $string .= "<codigo>{$tax->code}</codigo>";
            $string .= "<codigoPorcentaje>{$tax->percentageCode}</codigoPorcentaje>";
            $string .= '<baseImponible>'.number_format($tax->base, 2, '.', '').'</baseImponible>';
            $string .= '<valor>'.($tax->code === 2
                    ? round($tax->base * $tax->percentage / 100, 2)
                    : $order->ice).'</valor>';
            $string .= '</totalImpuesto>';
        }
        $string .= '</totalConImpuestos>';

        $string .= "<motivo>{$order->reason}</motivo>";
        $string .= '</infoNotaCredito>';

        $string .= '<detalles>';
        foreach ($this->items as $detail) {
            $subTotal = $detail->quantity * $detail->price;
            $total = round($subTotal + $detail->valice - $detail->discount, 2);

            $string .= '<detalle>';
            $string .= "<codigoInterno>{$detail->codeproduct}</codigoInterno>";
            $string .= "<descripcion>{$detail->name}</descripcion>";
            $string .= '<cantidad>'.round($detail->quantity, $company->decimal).'</cantidad>';
            $string .= '<precioUnitario>'.round($detail->price, $company->decimal).'</precioUnitario>';
            $string .= "<descuento>{$detail->discount}</descuento>";
            $string .= '<precioTotalSinImpuesto>'.round($total, 2).'</precioTotalSinImpuesto>';
            $string .= $this->itemImpuestos($detail, $subTotal);
            $string .= '</detalle>';
        }
        $string .= '</detalles>';

        $string .= '</notaCredito>';

        return $string;
    }
}
