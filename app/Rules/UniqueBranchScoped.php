<?php

namespace App\Rules;

use App\Models\Branch;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class UniqueBranchScoped implements Rule
{
    protected string $table;

    protected string $column;

    protected ?int $ignoreId;

    protected ?string $idColumn;

    /**
     * @param  string  $table  Nombre de la tabla
     * @param  string  $column  Columna a validar (default: campo validado)
     * @param  int|null  $ignoreId  ID a ignorar (para updates)
     * @param  string|null  $idColumn  Columna del ID (default: 'id')
     */
    public function __construct(
        string $table,
        string $column = 'NULL',
        ?int $ignoreId = null,
        ?string $idColumn = 'id'
    ) {
        $this->table = $table;
        $this->column = $column;
        $this->ignoreId = $ignoreId;
        $this->idColumn = $idColumn;
    }

    public function passes($attribute, $value)
    {
        $column = $this->column === 'NULL' ? $attribute : $this->column;

        // Obtener branch del usuario autenticado
        $companyId = Auth::user()->company?->id;

        if (! $companyId) {
            return true; // Si no hay company, dejar pasar (o retornar false)
        }

        $branch = Branch::where('company_id', $companyId)
            ->orderBy('created_at')
            ->first();

        if (! $branch) {
            return true;
        }

        // Query base
        $query = \DB::table($this->table)
            ->where($column, $value)
            ->where('branch_id', $branch->id);

        // Ignorar ID si es update
        if ($this->ignoreId) {
            $query->where($this->idColumn, '!=', $this->ignoreId);
        }

        return $query->doesntExist();
    }

    public function message()
    {
        return 'El :attribute ya existe en esta sucursal.';
    }
}
