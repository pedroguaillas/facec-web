<?php

namespace App\Services\Product;

use App\Models\Branch;
use App\Models\Product\Warehouse;

/**
 * Resuelve la bodega "por defecto" de una sucursal — la más antigua, mismo
 * criterio que BranchScope usa para resolver "la" sucursal. Si la empresa
 * nunca configuró ninguna, crea "Bodega principal" sola: el multi-bodega es
 * opcional, no un paso de setup obligatorio. Sin global scopes: la
 * sucursal ya viene resuelta y también se usa fuera de una request.
 */
class WarehouseResolver
{
    public function forBranch(Branch $branch): Warehouse
    {
        return Warehouse::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->orderBy('created_at')
            ->first()
            ?? Warehouse::create([
                'branch_id' => $branch->id,
                'name' => 'Bodega principal',
            ]);
    }
}
