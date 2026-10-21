<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class InventoryValuationExport implements FromArray, WithColumnFormatting, WithHeadings
{
    /**
     * @param  array{date: string, total_value: float, items: Collection<int, array<string, mixed>>}  $valuation
     */
    public function __construct(protected array $valuation) {}

    public function array(): array
    {
        $rows = $this->valuation['items']->map(fn (array $item) => [
            $item['code'],
            $item['name'],
            $item['quantity'],
            $item['average_cost'],
            $item['value'],
        ])->all();

        $rows[] = ['', 'Total', '', '', $this->valuation['total_value']];

        return $rows;
    }

    public function headings(): array
    {
        return ['Código', 'Producto', 'Cantidad', 'Costo promedio', 'Valor'];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
