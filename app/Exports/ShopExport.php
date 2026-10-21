<?php

namespace App\Exports;

use App\Models\Shop\Shop;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ShopExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    protected bool $beforeTaxChange;

    protected bool $hasBase5;

    public function __construct(
        protected int $branchId,
        protected string $year,
        protected string $month,
    ) {
        $dateToCheck = Carbon::parse("{$this->year}-{$this->month}-01");
        $this->beforeTaxChange = $dateToCheck->isBefore(Carbon::parse('2024-04-01'));
        $this->hasBase5 = ! $this->beforeTaxChange;
    }

    public function query(): Builder
    {
        $baseField = $this->beforeTaxChange ? 'base12' : 'base15';
        $ivaField = $this->beforeTaxChange ? 'iva' : 'iva15';

        return Shop::query()
            ->join('providers AS p', 'p.id', 'provider_id')
            ->join('voucher_types AS vt', 'vt.id', 'shops.voucher_type_id')
            ->select(
                'vt.name AS voucher_type_name', 'date', 'authorization', 'serie',
                'no_iva', 'base0', 'base5', $baseField, 'iva5',
                $ivaField, 'total', 'shops.state', 'p.identication', 'p.name'
            )
            ->whereYear('date', $this->year)
            ->whereMonth('date', $this->month)
            ->where('shops.branch_id', $this->branchId);
    }

    public function headings(): array
    {
        $headings = [
            'Identificación',
            'Proveedor',
            'Comprobante',
            'Fecha',
            'Autorización',
            'N° de comprobante',
            'No IVA',
            'No grabada',
        ];

        if ($this->hasBase5) {
            $headings[] = 'Gra 5%';
        }

        $headings[] = 'Gra '.($this->beforeTaxChange ? '12%' : '15%');

        if ($this->hasBase5) {
            $headings[] = 'IVA 5%';
        }

        $headings[] = 'IVA '.($this->beforeTaxChange ? '12%' : '15%');
        $headings[] = 'Total';
        $headings[] = 'Estado L/C';

        return $headings;
    }

    public function map($shop): array
    {
        $row = [
            $shop->identication,
            $shop->name,
            $shop->voucher_type_name,
            $shop->date,
            $shop->authorization,
            $shop->serie,
            $shop->no_iva,
            $shop->base0,
        ];

        if ($this->hasBase5) {
            $row[] = $shop->base5;
        }

        $row[] = $this->beforeTaxChange ? $shop->base12 : $shop->base15;

        if ($this->hasBase5) {
            $row[] = $shop->iva5;
        }

        $row[] = $this->beforeTaxChange ? $shop->iva : $shop->iva15;
        $row[] = $shop->total;
        $row[] = $shop->state;

        return $row;
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'E' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
