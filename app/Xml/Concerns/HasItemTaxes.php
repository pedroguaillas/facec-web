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
     * Mapa código IVA (iva_taxes.code) => columnas ya calculadas y
     * persistidas en la Orden por OrderTotalsCalculator. `iva` field es
     * null cuando la tarifa es 0% (no objeto de IVA incluido): el valor
     * siempre es 0, no hace falta columna.
     *
     * @var array<int, array{base: string, iva: string|null}>
     */
    private const ORDER_IVA_FIELD_MAP = [
        0 => ['base' => 'base0', 'iva' => null],
        5 => ['base' => 'base5', 'iva' => 'iva5'],
        8 => ['base' => 'base8', 'iva' => 'iva8'],
        4 => ['base' => 'base15', 'iva' => 'iva15'],
        6 => ['base' => 'no_iva', 'iva' => null],
    ];

    /**
     * Agrupa los ítems por código de impuesto y porcentaje para saber qué
     * bloques <totalImpuesto> corresponden. Los valores de base/valor NO se
     * recalculan sumando ítems: se toman tal cual de la Orden, que ya los
     * dejó calculados y persistidos (OrderTotalsCalculator) como fuente de
     * verdad. Solo el grupo ICE (sin columna de base persistida) sigue
     * sumando desde los ítems; su valor ya venía de $order->ice.
     * Devuelve un array de stdClass con: code, percentageCode, percentage, base, valor.
     */
    protected function groupTaxes(iterable $items, $order): array
    {
        $taxes = [];

        foreach ($items as $item) {
            // IVA
            $item->code = 2;
            $item->percentageCode = $item->iva;

            if ($this->findTaxGroup($taxes, $item) === -1) {
                $map = self::ORDER_IVA_FIELD_MAP[$item->iva] ?? null;

                $taxIva = new stdClass;
                $taxIva->code = 2;
                $taxIva->percentageCode = $item->iva;
                $taxIva->percentage = $item->percentage;
                $taxIva->base = $map ? (float) $order->{$map['base']} : 0.0;
                $taxIva->valor = $map && $map['iva'] ? (float) $order->{$map['iva']} : 0.0;
                $taxes[] = $taxIva;
            }

            // ICE (opcional): sin columna de base persistida en la Orden,
            // se sigue sumando desde los ítems.
            if ($item->codice !== null && $item->valice > 0) {
                $subTotal = $item->quantity * $item->price;

                $taxIce = new stdClass;
                $taxIce->code = 3;
                $taxIce->percentageCode = $item->codice;

                $index = $this->findTaxGroup($taxes, $taxIce);
                if ($index !== -1) {
                    $taxes[$index]->base += $subTotal;
                } else {
                    $taxIce->base = $subTotal;
                    $taxIce->valor = (float) $order->ice;
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

    /**
     * Renderiza el bloque <detalles> completo (factura y nota de crédito
     * comparten esta estructura, solo cambia el tag del código de producto
     * y si incluye código auxiliar).
     */
    protected function renderDetalles(iterable $items, int $decimal, string $codeTag, bool $includeAuxCode): string
    {
        $string = '<detalles>';

        foreach ($items as $detail) {
            $subTotal = $detail->quantity * $detail->price;
            $total = round($subTotal + $detail->valice - $detail->discount, 2);

            $string .= '<detalle>';
            $string .= "<{$codeTag}>{$detail->codeproduct}</{$codeTag}>";
            $string .= $includeAuxCode && $detail->aux_cod ? "<codigoAuxiliar>{$detail->aux_cod}</codigoAuxiliar>" : null;
            $string .= "<descripcion>{$detail->name}</descripcion>";
            $string .= '<cantidad>'.round($detail->quantity, $decimal).'</cantidad>';
            $string .= '<precioUnitario>'.round($detail->price, $decimal).'</precioUnitario>';
            $string .= "<descuento>{$detail->discount}</descuento>";
            $string .= "<precioTotalSinImpuesto>{$total}</precioTotalSinImpuesto>";
            $string .= $this->itemImpuestos($detail, $subTotal);
            $string .= '</detalle>';
        }

        $string .= '</detalles>';

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
