<?php

namespace App\Imports;

use App\Models\Product\Product;
use App\Rules\AvailablePresentationCodeRule;
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

    /**
     * Costo unitario por código de producto, para el "Inventario inicial".
     *
     * @var array<string, float>
     */
    protected array $unitCosts = [];

    public function __construct(
        protected int $branchId,
    ) {}

    public function model(array $row): Product
    {
        $isProduct = (int) $row['tipo'] === Product::TYPE_PRODUCT;

        // El producto no guarda costo: se usa solo al registrar el saldo inicial en el kardex.
        if ($isProduct && ($row['costo'] ?? null) !== null && $row['costo'] !== '') {
            $this->unitCosts[(string) $row['codigo']] = (float) $row['costo'];
        }

        return new Product([
            'branch_id' => $this->branchId,
            'code' => $row['codigo'],
            'aux_cod' => $row['codigo_auxiliar'] ?: null,
            'type_product' => $row['tipo'],
            'name' => $row['nombre'],
            'price1' => $row['precio'],
            'iva' => $row['iva'],
            'ice' => $row['ice'] ?: null,
            // Inserción en lote (sin eventos de modelo): los servicios no llevan stock.
            'stock' => $isProduct ? ($row['stock'] ?: null) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:25', new UniqueBranchScoped('products', 'code'), new AvailablePresentationCodeRule($this->branchId, includeProducts: false)],
            'codigo_auxiliar' => ['nullable', 'string', 'max:25', new RequiredAuxCodRule],
            'tipo' => ['required', 'integer', 'in:1,2'],
            'nombre' => ['required', 'string', 'max:300'],
            'precio' => ['required', 'numeric', 'min:0'],
            'iva' => ['required', 'integer', 'exists:iva_taxes,code'],
            'ice' => ['nullable', 'integer', 'exists:ice_cataloges,code'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, float>
     */
    public function unitCosts(): array
    {
        return $this->unitCosts;
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
