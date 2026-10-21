<?php

namespace App\Services\Product;

use App\Models\Product\ProductPresentation;

/**
 * Completa las líneas de venta/compra con su presentación: `presentation_id`
 * y una copia del `conversion_factor` (1 si no hay presentación). En ventas
 * también arma el código/descripción propios de la línea ("Huevos (Cubeta
 * x30)") para que el XML, el PDF y el detalle muestren la presentación vendida.
 * Una sola consulta para todas las líneas.
 */
class PresentationLineResolver
{
    /**
     * @param  array<int, array<string, mixed>>  $products  Líneas tal como llegan del request.
     * @return array<int, array{presentation_id: int|null, conversion_factor: float, code: string|null, description: string|null}> Por índice de línea.
     */
    public function resolve(array $products): array
    {
        $presentationIds = collect($products)->pluck('presentation_id')->filter()->unique()->all();

        $presentations = $presentationIds
            ? ProductPresentation::with('product:id,name,code')->whereIn('id', $presentationIds)->get()->keyBy('id')
            : collect();

        return array_map(function (array $line) use ($presentations) {
            $presentation = isset($line['presentation_id']) ? $presentations->get($line['presentation_id']) : null;

            if (! $presentation) {
                return ['presentation_id' => null, 'conversion_factor' => 1.0, 'code' => null, 'description' => null];
            }

            return [
                'presentation_id' => $presentation->id,
                'conversion_factor' => (float) $presentation->conversion_factor,
                'code' => $presentation->code,
                'description' => mb_substr("{$presentation->product->name} ({$presentation->name})", 0, 300),
            ];
        }, $products);
    }
}
