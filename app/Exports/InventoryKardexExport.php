<?php

namespace App\Exports;

use App\Models\Inventory;
use App\Models\Product\Product;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Encabezado con el producto, luego los movimientos en orden de registro.
 */
class InventoryKardexExport implements FromArray, WithColumnFormatting
{
    /**
     * @param  Collection<int, Inventory>  $movements
     */
    public function __construct(
        protected Product $product,
        protected Collection $movements,
    ) {}

    public function array(): array
    {
        $rows = [
            ['Producto', "{$this->product->code} - {$this->product->name}"],
            [],
            ['Fecha', 'Tipo', 'Documento', 'Bodega', 'Nota', 'Entrada', 'Salida', 'Costo unit.', 'Saldo', 'Costo prom.', 'Valor', 'Usuario'],
        ];

        foreach ($this->movements as $movement) {
            $isIn = $movement->direction === InventoryMovementTypes::IN;

            $rows[] = [
                $movement->date,
                $movement->type,
                $movement->source?->serie,
                $movement->warehouse?->name,
                $movement->note,
                $isIn ? $movement->quantity : null,
                $isIn ? null : $movement->quantity,
                $movement->unit_cost,
                $movement->new_balance,
                $movement->average_cost,
                $movement->balance_value,
                $movement->user?->name,
            ];
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
