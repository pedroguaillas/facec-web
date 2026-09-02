<?php

namespace App\Xml;

use App\Models\Order\OrderAditional;
use App\Models\Order\Repayment\Repayment;
use App\Models\Order\Repayment\RepaymentTax;
use App\Xml\Concerns\HasItemTaxes;

class InvoiceBuilder extends BaseVoucherBuilder
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

        $repayments = $this->loadRepayments();
        $additionals = OrderAditional::where('order_id', $order->id)->get();

        $typeId = match ($order->type_identification) {
            'ruc' => '04',
            'cédula' => '05',
            'pasaporte' => '06',
            'cf' => '07',
        };

        $string = '<?xml version="1.0" encoding="UTF-8"?>';
        $string .= "<factura id=\"comprobante\" version=\"1.{$version}.0\">";
        $string .= $this->infoTributaria();

        $string .= '<infoFactura>';

        $date = new \DateTime($order->date);
        $string .= '<fechaEmision>'.$date->format('d/m/Y').'</fechaEmision>';
        $string .= '<obligadoContabilidad>'.($company->accounting ? 'SI' : 'NO').'</obligadoContabilidad>';
        $string .= "<tipoIdentificacionComprador>{$typeId}</tipoIdentificacionComprador>";
        $string .= $order->guia !== null ? "<guiaRemision>{$order->guia}</guiaRemision>" : null;
        $string .= '<razonSocialComprador>'.str_replace('&', 'Y', $order->name).'</razonSocialComprador>';
        $string .= "<identificacionComprador>{$order->identication}</identificacionComprador>";
        $string .= $order->address !== null ? "<direccionComprador>{$order->address}</direccionComprador>" : null;
        $string .= "<totalSinImpuestos>{$order->sub_total}</totalSinImpuestos>";
        $string .= "<totalDescuento>{$order->discount}</totalDescuento>";

        // Desembolso (reembolsos)
        if ($repayments->count()) {
            $totalBase = $repayments->sum('base');
            $totalIva = $repayments->sum('iva');

            $string .= '<codDocReembolso>41</codDocReembolso>';
            $string .= '<totalComprobantesReembolso>'.number_format($totalBase + $totalIva, 2).'</totalComprobantesReembolso>';
            $string .= '<totalBaseImponibleReembolso>'.number_format($totalBase, 2).'</totalBaseImponibleReembolso>';
            $string .= '<totalImpuestoReembolso>'.number_format($totalIva, 2).'</totalImpuestoReembolso>';
        }

        // totalConImpuestos
        $aditionalDiscount = $order->discount;
        foreach ($this->items as $oi) {
            $aditionalDiscount -= $oi->discount;
        }

        $string .= '<totalConImpuestos>';
        foreach ($this->groupTaxes($this->items, $order) as $tax) {
            $string .= '<totalImpuesto>';
            $string .= "<codigo>{$tax->code}</codigo>";
            $string .= "<codigoPorcentaje>{$tax->percentageCode}</codigoPorcentaje>";
            $string .= $tax->code === 2 && $aditionalDiscount
                ? '<descuentoAdicional>'.round($aditionalDiscount, 2).'</descuentoAdicional>'
                : null;
            $string .= '<baseImponible>'.number_format($tax->base, 2, '.', '').'</baseImponible>';
            $string .= $tax->code === 2 ? "<tarifa>{$tax->percentage}</tarifa>" : null;
            $string .= '<valor>'.$tax->valor.'</valor>';
            $string .= '</totalImpuesto>';
        }
        $string .= '</totalConImpuestos>';

        $string .= '<propina>0</propina>';
        $string .= '<importeTotal>'.round($order->total, 2).'</importeTotal>';
        $string .= '<moneda>DOLAR</moneda>';
        $string .= $order->plate !== null ? "<placa>{$order->plate}</placa>" : null;

        $string .= '<pagos>';
        $string .= '<pago>';
        $string .= '<formaPago>'.str_pad($order->pay_method, 2, '0', STR_PAD_LEFT).'</formaPago>';
        $string .= "<total>{$order->total}</total>";
        $string .= '</pago>';
        $string .= '</pagos>';

        $string .= '</infoFactura>';

        // Detalles
        $string .= $this->renderDetalles($this->items, $company->decimal, 'codigoPrincipal', includeAuxCode: true);

        // Reembolsos
        if ($repayments->count()) {
            $string .= $this->buildRepayments($repayments);
        }

        // Info adicional
        if ($additionals->count()) {
            $string .= '<infoAdicional>';
            foreach ($additionals as $additional) {
                $string .= "<campoAdicional nombre=\"{$additional->name}\">{$additional->description}</campoAdicional>";
            }
            $string .= '</infoAdicional>';
        }

        $string .= '</factura>';

        return $string;
    }

    private function loadRepayments()
    {
        return Repayment::selectRaw(
            'repayments.id, type_id_prov, identification, cod_country, type_prov, type_document, sequential, date, authorization, SUM(base) AS base, SUM(iva) AS iva'
        )
            ->join('repayment_taxes AS rt', 'repayments.id', 'repayment_id')
            ->groupBy('id', 'type_id_prov', 'identification', 'cod_country', 'type_prov', 'type_document', 'sequential', 'date', 'authorization')
            ->where('order_id', $this->order->id)
            ->get();
    }

    private function buildRepayments($repayments): string
    {
        $result = '<reembolsos>';

        foreach ($repayments as $repayment) {
            $result .= '<reembolsoDetalle>';
            $result .= "<tipoIdentificacionProveedorReembolso>0{$repayment->type_id_prov}</tipoIdentificacionProveedorReembolso>";
            $result .= "<identificacionProveedorReembolso>{$repayment->identification}</identificacionProveedorReembolso>";
            $result .= "<codPaisPagoProveedorReembolso>{$repayment->cod_country}</codPaisPagoProveedorReembolso>";
            $result .= "<tipoProveedorReembolso>0{$repayment->type_prov}</tipoProveedorReembolso>";
            $result .= "<codDocReembolso>0{$repayment->type_document}</codDocReembolso>";
            $result .= '<estabDocReembolso>'.substr($repayment->sequential, 0, 3).'</estabDocReembolso>';
            $result .= '<ptoEmiDocReembolso>'.substr($repayment->sequential, 4, 3).'</ptoEmiDocReembolso>';
            $result .= '<secuencialDocReembolso>'.substr($repayment->sequential, 8, 9).'</secuencialDocReembolso>';
            $result .= '<fechaEmisionDocReembolso>'.date('d/m/Y', strtotime($repayment->date)).'</fechaEmisionDocReembolso>';
            $result .= "<numeroautorizacionDocReemb>{$repayment->authorization}</numeroautorizacionDocReemb>";

            $result .= '<detalleImpuestos>';
            foreach (RepaymentTax::select('iva_tax_code', 'base', 'iva', 'percentage')->where('repayment_id', $repayment->id)->get() as $tax) {
                $result .= '<detalleImpuesto>';
                $result .= '<codigo>2</codigo>';
                $result .= "<codigoPorcentaje>{$tax->iva_tax_code}</codigoPorcentaje>";
                $result .= '<tarifa>'.floatval($tax->percentage).'</tarifa>';
                $result .= "<baseImponibleReembolso>{$tax->base}</baseImponibleReembolso>";
                $result .= "<impuestoReembolso>{$tax->iva}</impuestoReembolso>";
                $result .= '</detalleImpuesto>';
            }
            $result .= '</detalleImpuestos>';

            $result .= '</reembolsoDetalle>';
        }

        $result .= '</reembolsos>';

        return $result;
    }
}
