<?php

namespace App\Services\Shop\Retention;

use Illuminate\Support\Facades\Log;

class RetentionTotalsCalculator
{
    private const TOLERANCE = 0.01;

    /**
     * Recalculate the retained value of each retention line server-side.
     *
     * The recalculated value is always the source of truth. When the frontend
     * sent a `value` that differs by more than the tolerance, the discrepancy
     * is logged but the guardado is not blocked.
     *
     * @param  array<int, array{code:mixed,tax_code:mixed,base:float,porcentage:float,value?:float}>  $taxes
     * @return array<int, array{code:mixed,tax_code:mixed,base:float,porcentage:float,value:float}>
     */
    public function recalculate(array $taxes, ?int $shopId = null): array
    {
        return array_map(function (array $tax) use ($shopId) {
            $base = (float) $tax['base'];
            $porcentage = (float) $tax['porcentage'];
            $calculated = round($base * $porcentage / 100, 2);

            if (array_key_exists('value', $tax) && $tax['value'] !== null) {
                $submitted = (float) $tax['value'];
                $diff = round(abs($calculated - $submitted), 2);

                if ($diff > self::TOLERANCE) {
                    Log::warning('Retention value mismatch: valor del frontend descartado, se usa el recalculado server-side', [
                        'shop_id' => $shopId,
                        'tax_code' => $tax['tax_code'],
                        'base' => $base,
                        'porcentage' => $porcentage,
                        'submitted' => $submitted,
                        'calculated' => $calculated,
                        'diff' => $diff,
                    ]);
                }
            }

            $tax['value'] = $calculated;

            return $tax;
        }, $taxes);
    }
}
