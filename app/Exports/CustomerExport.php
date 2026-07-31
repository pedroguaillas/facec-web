<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CustomerExport implements FromQuery, WithColumnFormatting, WithHeadings, WithMapping
{
    public function __construct(protected int $branchId) {}

    public function query()
    {
        return Customer::query()
            ->select('type_identification', 'identication', 'name', 'address', 'phone', 'email')
            ->where('branch_id', $this->branchId)
            ->where('type_identification', '<>', 'cf');
    }

    public function headings(): array
    {
        return ['Tipo ID', 'Identificación', 'Cliente', 'Dirección', 'Teléfono', 'Correo'];
    }

    public function map($customer): array
    {
        return [
            $customer->type_identification,
            $customer->identication,
            $customer->name,
            $customer->address,
            $customer->phone,
            $customer->email,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_TEXT,
            'E' => NumberFormat::FORMAT_TEXT,
        ];
    }
}
