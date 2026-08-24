<?php

namespace App\Xml\Concerns;

use stdClass;

/**
 * Agrupa y renderiza impuestos de ítems (IVA e ICE) para comprobantes con detalles.
 * Usado por: InvoiceBuilder, CreditNoteBuilder, SettlementOnPurchaseBuilder.
 */
trait HasItemTaxes
{
    /**
     * Agrupa los ítems por código de impuesto y porcentaje,
     * sumando sus bases imponibles. Devuelve un array de stdClass
     * con: code, percentageCode, percentage, base.
     */
    protected function groupTaxes(iterable $items): array
    {
        $taxes = [];

        foreach ($items as $item) {
            $subTotal = $item->quantity * $item->price;
            $total = $subTotal + $item->valice - $item->discount;

            // IVA
            $item->code = 2;
            $item->percentageCode = $item->iva;

            $index = $this->findTaxGroup($taxes, $item);
            if ($index !== -1) {
                $taxes[$index]->base += $total;
            } else {
                $taxIva = new stdClass;
                $taxIva->code = 2;
                $taxIva->percentageCode = $item->iva;
                $taxIva->percentage = $item->percentage;
                $taxIva->base = $total;
                $taxes[] = $taxIva;
            }

            // ICE (opcional)
            if ($item->codice !== null && $item->valice > 0) {
                $taxIce = new stdClass;
                $taxIce->code = 3;
                $taxIce->percentageCode = $item->codice;

                $index = $this->findTaxGroup($taxes, $taxIce);
                if ($index !== -1) {
                    $taxes[$index]->base += $subTotal;
                } else {
                    $taxIce->base = $subTotal;
                    $taxes[] = $taxIce;
                }
            }
        }

        return $taxes;
    }

    /**
     * Renderiza el bloque <impuestos> de un ítem de detalle (IVA + ICE opcional).
     */
    protected function itemImpuestos($detail, float $subTotal): string
    {
        $string = '<impuestos>';

        // IVA (siempre presente): el impuesto se calcula sobre la base sin
        // redondear para evitar el doble redondeo; baseImponible solo se
        // redondea al mostrarse como valor monetario.
        $ivaBase = $subTotal + $detail->valice - $detail->discount;
        $string .= '<impuesto>';
        $string .= '<codigo>2</codigo>';
        $string .= "<codigoPorcentaje>{$detail->iva}</codigoPorcentaje>";
        $string .= "<tarifa>{$detail->percentage}</tarifa>";
        $string .= '<baseImponible>'.round($ivaBase, 2).'</baseImponible>';
        $string .= '<valor>'.round($detail->percentage * $ivaBase * .01, 2).'</valor>';
        $string .= '</impuesto>';

        // ICE (opcional)
        if ($detail->codice) {
            $string .= '<impuesto>';
            $string .= '<codigo>3</codigo>';
            $string .= "<codigoPorcentaje>{$detail->codice}</codigoPorcentaje>";
            $string .= '<tarifa>0</tarifa>';
            $string .= '<baseImponible>'.number_format($subTotal, 2, '.', '').'</baseImponible>';
            $string .= "<valor>{$detail->valice}</valor>";
            $string .= '</impuesto>';
        }

        $string .= '</impuestos>';

        return $string;
    }

    private function findTaxGroup(array $taxes, $tax): int
    {
        foreach ($taxes as $i => $grouped) {
            if ($grouped->code === $tax->code && $grouped->percentageCode === $tax->percentageCode) {
                return $i;
            }
        }

        return -1;
    }
}
