<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Margen % = utilidad / ventas × 100 (0 si no hubo ventas).
 */
class InventoryCostOfSalesExport implements FromArray, WithColumnFormatting, WithHeadings
{
    /**
     * @param  array{from: string, to: string, totals: array<string, float>, items: Collection<int, array<string, mixed>>}  $report
     */
    public function __construct(protected array $report) {}

    public function array(): array
    {
        $rows = $this->report['items']->map(fn (array $item) => [
            $item['code'],
            $item['name'],
            $item['quantity'],
            $item['revenue'],
            $item['cost'],
            $item['gross_profit'],
            $this->margin($item['gross_profit'], $item['revenue']),
        ])->all();

        $totals = $this->report['totals'];
        $rows[] = ['', 'Total', '', $totals['revenue'], $totals['cost'], $totals['gross_profit'], $this->margin($totals['gross_profit'], $totals['revenue'])];

        return $rows;
    }

    public function headings(): array
    {
        return ['Código', 'Producto', 'Cantidad', 'Ventas', 'Costo', 'Utilidad', 'Margen %'];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }

    private function margin(float $profit, float $revenue): float
    {
        return $revenue != 0.0 ? round($profit / $revenue * 100, 2) : 0.0;
    }
}
