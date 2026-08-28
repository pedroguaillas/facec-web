<?php

namespace App\Exports;

use App\Models\Order\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    protected bool $beforeTaxChange;

    protected bool $hasBase5;

    public function __construct(
        protected int $branchId,
        protected string $year,
        protected string $month,
        protected bool $companyBase5,
    ) {
        $dateToCheck = Carbon::parse("{$this->year}-{$this->month}-01");
        $this->beforeTaxChange = $dateToCheck->isBefore(Carbon::parse('2024-04-01'));
        $this->hasBase5 = ! $this->beforeTaxChange && $this->companyBase5;
    }

    public function query(): Builder
    {
        $baseField = $this->beforeTaxChange ? 'base12' : 'base15';
        $ivaField = $this->beforeTaxChange ? 'iva' : 'iva15';

        return Order::query()
            ->join('customers AS c', 'c.id', 'customer_id')
            ->select(
                'voucher_type', 'date', 'authorization', 'serie',
                'no_iva', 'base0', 'base5', $baseField, 'iva5',
                $ivaField, 'total', 'orders.state', 'identication', 'c.name'
            )
            ->whereYear('date', $this->year)
            ->whereMonth('date', $this->month)
            ->where('orders.branch_id', $this->branchId);
    }

    public function headings(): array
    {
        $headings = [
            'Identificación',
            'Cliente',
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
        $headings[] = 'Estado';

        return $headings;
    }

    public function map($order): array
    {
        $row = [
            $order->identication,
            $order->name,
            $this->voucherTypeName($order->voucher_type),
            $order->date,
            $order->authorization,
            $order->serie,
            $order->no_iva,
            $order->base0,
        ];

        if ($this->hasBase5) {
            $row[] = $order->base5;
        }

        $row[] = $this->beforeTaxChange ? $order->base12 : $order->base15;

        if ($this->hasBase5) {
            $row[] = $order->iva5;
        }

        $row[] = $this->beforeTaxChange ? $order->iva : $order->iva15;
        $row[] = $order->total;
        $row[] = $order->state;

        return $row;
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'E' => NumberFormat::FORMAT_TEXT,
        ];
    }

    private function voucherTypeName(int $type): string
    {
        return match ($type) {
            1 => 'Factura',
            2 => 'Nota de venta',
            3 => 'Liquidación en compra',
            4 => 'Nota de crédito',
            default => '',
        };
    }
}
