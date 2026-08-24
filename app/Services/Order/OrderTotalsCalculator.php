<?php

namespace App\Services\Order;

use App\Models\Product\IvaTax;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrderTotalsCalculator
{
    /**
     * Diferencia máxima aceptada (redondeo normal) entre lo enviado por el
     * frontend y el recálculo server-side antes de dejar registro en el log.
     */
    private const TOLERANCE = 0.01;

    /** Código especial de IVA "No objeto de IVA" (no existe en la tabla iva_taxes). */
    private const NO_IVA_CODE = 6;

    /**
     * Tarifa 12% (código IVA 2) descontinuada: ya no se genera en Ventas ni
     * Compras, `base12`/`iva` quedan fijos en 0 y solo se conservan para
     * visualizar comprobantes históricos (exports, edición read-only).
     */
    private const DEPRECATED_12_CODE = 2;

    /**
     * Recalcula subtotales y totales monetarios de una Orden como fuente de
     * verdad server-side, replicando la misma base imponible que arma el XML
     * enviado al SRI (ver HasItemTaxes::groupTaxes).
     *
     * @param  array<int, array{quantity: float|int, price: float|int, discount?: float|int, ice?: float|int, iva: int}>  $products
     * @param  array<string, mixed>  $submitted  Datos crudos del frontend, solo para comparar/loggear (nunca para calcular).
     * @param  Collection<int, float>|null  $percentages  Mapa código IVA => porcentaje. Si es null se consulta la BD.
     * @return array{no_iva: float, base0: float, base5: float, base8: float, base12: float, base15: float, iva5: float, iva8: float, iva: float, iva15: float, ice: float, sub_total: float, total: float}
     */
    public function calculate(
        array $products,
        float $discount,
        array $submitted = [],
        ?int $orderId = null,
        ?Collection $percentages = null
    ): array {
        $percentages ??= IvaTax::all(['code', 'percentage'])->pluck('percentage', 'code');

        $bases = [
            self::NO_IVA_CODE => 0.0,
            0 => 0.0,
            5 => 0.0,
            8 => 0.0,
            4 => 0.0,
        ];

        $iceTotal = 0.0;

        foreach ($products as $product) {
            $ice = (float) ($product['ice'] ?? 0);
            $itemBase = round((float) $product['quantity'] * (float) $product['price'], 2)
                - (float) ($product['discount'] ?? 0)
                + $ice;

            $code = (int) $product['iva'];

            // Tarifa 12% descontinuada: no se suma a ningún bucket ni al
            // sub_total/total recalculados (ver DEPRECATED_12_CODE arriba).
            if ($code !== self::DEPRECATED_12_CODE) {
                if (! array_key_exists($code, $bases)) {
                    $bases[$code] = 0.0;
                }
                $bases[$code] += $itemBase;
            }

            $iceTotal += $ice;
        }

        $base0 = round($bases[0], 2);
        $base5 = round($bases[5], 2);
        $base8 = round($bases[8], 2);
        $base12 = 0.0;
        $base15 = round($bases[4], 2);
        $noIva = round($bases[self::NO_IVA_CODE], 2);

        $iva5 = round($base5 * (float) ($percentages[5] ?? 0) / 100, 2);
        $iva8 = round($base8 * (float) ($percentages[8] ?? 0) / 100, 2);
        $iva = 0.0;
        $iva15 = round($base15 * (float) ($percentages[4] ?? 0) / 100, 2);

        $iceTotal = round($iceTotal, 2);
        $subTotal = round($noIva + $base0 + $base5 + $base8 + $base12 + $base15, 2);
        $ivaTotal = round($iva5 + $iva8 + $iva + $iva15, 2);
        $total = round($subTotal + $iceTotal + $ivaTotal - $discount, 2);

        $this->logMismatch($submitted, 'sub_total', $subTotal, $orderId);
        $this->logMismatch($submitted, 'total', $total, $orderId);

        return [
            'no_iva' => $noIva,
            'base0' => $base0,
            'base5' => $base5,
            'base8' => $base8,
            'base12' => $base12,
            'base15' => $base15,
            'iva5' => $iva5,
            'iva8' => $iva8,
            'iva' => $iva,
            'iva15' => $iva15,
            'ice' => $iceTotal,
            'sub_total' => $subTotal,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function logMismatch(array $submitted, string $field, float $calculated, ?int $orderId): void
    {
        if (! array_key_exists($field, $submitted)) {
            return;
        }

        $submittedValue = (float) $submitted[$field];
        $diff = round($submittedValue - $calculated, 2);

        if (abs($diff) <= self::TOLERANCE) {
            return;
        }

        Log::warning('Order totals mismatch: valor del frontend descartado, se usa el recalculado server-side', [
            'order_id' => $orderId,
            'field' => $field,
            'submitted' => $submittedValue,
            'calculated' => $calculated,
            'diff' => round($diff, 2),
        ]);
    }
}
