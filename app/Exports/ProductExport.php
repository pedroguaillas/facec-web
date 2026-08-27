<?php

namespace App\Exports;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    public function __construct(protected int $branchId) {}

    public function query(): Builder
    {
        return Product::query()
            ->select('code', 'aux_cod', 'type_product', 'name', 'price1', 'iva', 'ice', 'stock')
            ->where('branch_id', $this->branchId);
    }

    public function headings(): array
    {
        return ['Código', 'Código Auxiliar', 'Tipo', 'Nombre', 'Precio', 'IVA', 'ICE', 'Stock'];
    }

    public function map($product): array
    {
        return [
            $product->code,
            $product->aux_cod,
            $product->type_product,
            $product->name,
            $product->price1,
            $product->iva,
            $product->ice,
            $product->stock,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'B' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
