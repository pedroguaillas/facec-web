<?php

namespace App\Services\Order;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\Product\Product;
use App\StaticClasses\VoucherTypes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * IVA diferenciado (8%) en feriados para contribuyentes turísticos.
 *
 * Aplica cuando la empresa tiene `base8`, la fecha de la venta cae en un
 * feriado (`holidays`) y el producto está marcado `differentiated_iva`: el
 * ítem que viene con la tarifa general (15%) se graba con el 8%. Fuera de esas
 * condiciones, un ítem que venga con 8% vuelve a la tarifa general. El
 * backend decide la tarifa; lo que mande el front no cuenta.
 */
class DifferentiatedIvaService
{
    /** Código SRI de la tarifa general de IVA (15%). */
    const GENERAL_IVA_CODE = 4;

    /** Código SRI del IVA diferenciado (8%). */
    const DIFFERENTIATED_IVA_CODE = 8;

    /**
     * Comprobantes de venta donde se decide la tarifa por fecha. La nota de
     * crédito no: debe replicar el IVA de la factura que modifica.
     */
    const VOUCHER_SLUGS = [VoucherTypes::VENTA_FACTURA, VoucherTypes::VENTA_PROFORMA];

    /**
     * Feriado vigente en la fecha dada, solo si la empresa vende con IVA
     * diferenciado (`base8`).
     */
    public function holidayFor(Company $company, CarbonInterface|string $date): ?Holiday
    {
        if (! $company->base8) {
            return null;
        }

        return Holiday::covering($date)->orderBy('starts_at')->first();
    }

    /**
     * Feriados recientes y próximos (último mes en adelante), para que el
     * front decida la tarifa según la fecha elegida en el formulario. Vacío
     * si la empresa no tiene `base8`.
     *
     * @return Collection<int, Holiday>
     */
    public function upcomingHolidaysFor(Company $company): Collection
    {
        if (! $company->base8) {
            return new Collection;
        }

        return Holiday::whereDate('ends_at', '>=', now()->subMonth())
            ->orderBy('starts_at')
            ->get(['id', 'name', 'starts_at', 'ends_at']);
    }

    public function appliesTo(Company $company, CarbonInterface|string $date): bool
    {
        return $this->holidayFor($company, $date) !== null;
    }

    /**
     * Ítems de venta (`product_id`, `iva`, ...) con la tarifa que corresponde.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function applyToItems(Company $company, CarbonInterface|string $date, array $items): array
    {
        $differentiatedProductIds = $this->appliesTo($company, $date)
            ? Product::withoutGlobalScope('branch')
                ->whereIn('id', array_column($items, 'product_id'))
                ->where('differentiated_iva', true)
                ->pluck('id')
                ->all()
            : [];

        return array_map(function (array $item) use ($differentiatedProductIds) {
            if (! in_array((int) $item['iva'], [self::GENERAL_IVA_CODE, self::DIFFERENTIATED_IVA_CODE], true)) {
                return $item;
            }

            $item['iva'] = in_array((int) $item['product_id'], $differentiatedProductIds, true)
                ? self::DIFFERENTIATED_IVA_CODE
                : self::GENERAL_IVA_CODE;

            return $item;
        }, $items);
    }
}
