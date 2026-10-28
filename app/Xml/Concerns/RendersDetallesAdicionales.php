<?php

namespace App\Xml\Concerns;

use App\Models\Product\ProductTemplate;

/**
 * Características de producto en el XML (`<detallesAdicionales>` de cada
 * `<detalle>`): solo las marcadas `in_sri`, en su orden y hasta el máximo
 * del XSD. Ver docs/caracteristicas-producto.md §8.
 */
trait RendersDetallesAdicionales
{
    /**
     * @param  iterable<int, array{label: string, value: string, in_sri: bool, sort: int}>|null  $characteristics
     */
    protected function renderDetallesAdicionales(?iterable $characteristics): string
    {
        $sriCharacteristics = collect($characteristics ?? [])
            ->filter(fn (array $characteristic) => $characteristic['in_sri'] ?? false)
            ->sortBy('sort')
            ->take(ProductTemplate::MAX_SRI_FIELDS);

        if ($sriCharacteristics->isEmpty()) {
            return '';
        }

        $string = '<detallesAdicionales>';

        foreach ($sriCharacteristics as $characteristic) {
            $string .= '<detAdicional nombre="'.$this->escapeXml($characteristic['label']).'" valor="'.$this->escapeXml($characteristic['value']).'"/>';
        }

        return $string.'</detallesAdicionales>';
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
