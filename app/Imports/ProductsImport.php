<?php

namespace App\Imports;

use App\Models\Product\Product;
use App\Rules\RequiredAuxCodRule;
use App\Rules\UniqueBranchScoped;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ProductsImport implements SkipsOnFailure, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use Importable, SkipsFailures;

    public function __construct(
        protected int $branchId,
        protected bool $companyRequiresAuxCod,
    ) {}

    public function model(array $row): Product
    {
        return new Product([
            'branch_id' => $this->branchId,
            'code' => $row['codigo'],
            'aux_cod' => $row['codigo_auxiliar'] ?: null,
            'type_product' => $row['tipo'],
            'name' => $row['nombre'],
            'price1' => $row['precio'],
            'iva' => $row['iva'],
            'ice' => $row['ice'] ?: null,
            'stock' => $row['stock'] ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:25', new UniqueBranchScoped('products', 'code')],
            'codigo_auxiliar' => ['nullable', 'string', 'max:25', new RequiredAuxCodRule($this->companyRequiresAuxCod)],
            'tipo' => ['required', 'integer', 'in:1,2'],
            'nombre' => ['required', 'string', 'max:300'],
            'precio' => ['required', 'numeric', 'min:0'],
            'iva' => ['required', 'integer', 'exists:iva_taxes,code'],
            'ice' => ['nullable', 'integer', 'exists:ice_cataloges,code'],
            'stock' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function batchSize(): int
    {
        return 200;
    }

    public function chunkSize(): int
    {
        return 200;
    }
}
