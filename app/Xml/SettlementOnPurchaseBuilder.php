<?php

namespace App\Xml;

use stdClass;

class SettlementOnPurchaseBuilder extends BaseVoucherBuilder
{
    public function __construct(
        $company,
        private $shop,
        private $items,
    ) {
        parent::__construct($company);
    }

    protected function voucherTypeCode(): string
    {
        return str_pad($this->shop->voucher_type, 2, '0', STR_PAD_LEFT);
    }

    protected function accessKeyDate(): \DateTime
    {
        return new \DateTime($this->shop->date);
    }

    protected function serieRaw(): string
    {
        return $this->shop->serie;
    }

    public function build(): string
    {
        $shop = $this->shop;
        $company = $this->company;
        $version = $company->decimal > 2 ? 1 : 0;

        $typeId = match ($shop->type_identification) {
            'ruc' => '04',
            'cédula' => '05',
            'pasaporte' => '06',
        };

        $string = '<?xml version="1.0" encoding="UTF-8"?>';
        $string .= "<liquidacionCompra id=\"comprobante\" version=\"1.{$version}.0\">";
        $string .= $this->infoTributaria();

        $string .= '<infoLiquidacionCompra>';

        $date = new \DateTime($shop->date);
        $string .= '<fechaEmision>'.$date->format('d/m/Y').'</fechaEmision>';
        $string .= '<obligadoContabilidad>'.($company->accounting ? 'SI' : 'NO').'</obligadoContabilidad>';
        $string .= "<tipoIdentificacionProveedor>{$typeId}</tipoIdentificacionProveedor>";
        $string .= "<razonSocialProveedor>{$shop->name}</razonSocialProveedor>";
        $string .= "<identificacionProveedor>{$shop->identication}</identificacionProveedor>";
        $string .= "<totalSinImpuestos>{$shop->sub_total}</totalSinImpuestos>";
        $string .= "<totalDescuento>{$shop->discount}</totalDescuento>";

        $string .= '<totalConImpuestos>';
        foreach ($this->groupSettlementTaxes() as $tax) {
            $string .= '<totalImpuesto>';
            $string .= '<codigo>2</codigo>';
            $string .= "<codigoPorcentaje>{$tax->percentageCode}</codigoPorcentaje>";
            $string .= '<baseImponible>'.number_format($tax->base, 2, '.', '').'</baseImponible>';
            $string .= "<tarifa>{$tax->percentage}</tarifa>";
            $string .= '<valor>'.round($tax->base * $tax->percentage / 100, 2).'</valor>';
            $string .= '</totalImpuesto>';
        }
        $string .= '</totalConImpuestos>';

        $string .= '<importeTotal>'.round($shop->total, 2).'</importeTotal>';
        $string .= '<moneda>DOLAR</moneda>';

        $string .= '<pagos>';
        $string .= '<pago>';
        $string .= '<formaPago>20</formaPago>';
        $string .= "<total>{$shop->total}</total>";
        $string .= "<plazo>{$shop->total}</plazo>";
        $string .= '</pago>';
        $string .= '</pagos>';

        $string .= '</infoLiquidacionCompra>';

        $string .= '<detalles>';
        foreach ($this->items as $detail) {
            $total = round($detail->quantity * $detail->price, 2);
            $percentage = $detail->iva === 4 ? 15 : 0;

            $string .= '<detalle>';
            $string .= "<codigoPrincipal>{$detail->code}</codigoPrincipal>";
            $string .= "<descripcion>{$detail->name}</descripcion>";
            $string .= '<cantidad>'.round($detail->quantity, $company->decimal).'</cantidad>';
            $string .= '<precioUnitario>'.round($detail->price, $company->decimal).'</precioUnitario>';
            $string .= '<descuento>0.00</descuento>';
            $string .= "<precioTotalSinImpuesto>{$total}</precioTotalSinImpuesto>";
            $string .= '<impuestos>';
            $string .= '<impuesto>';
            $string .= '<codigo>2</codigo>';
            $string .= "<codigoPorcentaje>{$detail->iva}</codigoPorcentaje>";
            $string .= "<tarifa>{$percentage}</tarifa>";
            $string .= "<baseImponible>{$total}</baseImponible>";
            $string .= '<valor>'.round($percentage * $total * .01, 2).'</valor>';
            $string .= '</impuesto>';
            $string .= '</impuestos>';
            $string .= '</detalle>';
        }
        $string .= '</detalles>';

        $string .= '</liquidacionCompra>';

        return $string;
    }

    /**
     * Agrupa impuestos IVA de ítems de liquidación de compra.
     * Los ítems de compra no tienen ICE ni descuentos por ítem,
     * así que la base es simplemente quantity * price.
     */
    private function groupSettlementTaxes(): array
    {
        $taxes = [];

        foreach ($this->items as $item) {
            $base = (float) number_format($item->quantity * $item->price, 2, '.', '');
            $percentage = $item->iva === 4 ? 15 : 0;

            $found = false;
            foreach ($taxes as $tax) {
                if ($tax->percentageCode === $item->iva) {
                    $tax->base += $base;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $tax = new stdClass;
                $tax->percentageCode = $item->iva;
                $tax->percentage = $percentage;
                $tax->base = $base;
                $taxes[] = $tax;
            }
        }

        return $taxes;
    }
}
