<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Una fila por producto + bodega (un producto sin movimientos sale con bodega
 * vacía y stock 0). La última fila es el total del valor.
 */
class InventoryStockExport implements FromArray, WithColumnFormatting, WithHeadings
{
    /**
     * @param  Collection<int, array<string, mixed>>  $items  Filas de InventoryReportService::stockAll().
     */
    public function __construct(protected Collection $items) {}

    public function array(): array
    {
        $rows = [];

        foreach ($this->items as $item) {
            $warehouses = collect($item['warehouses']);

            if ($warehouses->isEmpty()) {
                $warehouses = collect([['name' => '', 'stock' => 0.0, 'average_cost' => 0.0, 'value' => 0.0]]);
            }

            foreach ($warehouses as $warehouse) {
                $rows[] = [
                    $item['code'],
                    $item['name'],
                    $warehouse['name'],
                    $warehouse['stock'],
                    $item['min_stock'],
                    $item['below_min'] ? 'Sí' : 'No',
                    $warehouse['average_cost'],
                    $warehouse['value'],
                ];
            }
        }

        $rows[] = ['', 'Total', '', '', '', '', '', round((float) $this->items->sum('value'), 2)];

        return $rows;
    }

    public function headings(): array
    {
        return ['Código', 'Producto', 'Bodega', 'Stock', 'Mínimo', 'Bajo mínimo', 'Costo promedio', 'Valor'];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
