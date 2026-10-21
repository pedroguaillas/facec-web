<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Product\OpeningBalanceFromStockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:seed-opening-balances {--company=* : Ids de empresa (por defecto: todas las que tienen inventario activo)}')]
#[Description('Registra "Inventario inicial" en el kardex a partir de products.stock, para productos sin movimientos en bodega. Idempotente: correr una vez tras desplegar el kardex con costeo (antes el stock lo mantenía el trigger stock_edit).')]
class SeedInventoryOpeningBalances extends Command
{
    public function handle(OpeningBalanceFromStockService $service): int
    {
        $companyIds = $this->option('company');

        $branches = Branch::query()
            ->whereHas('company', fn ($query) => $query->where('inventory', true))
            ->when($companyIds, fn ($query) => $query->whereIn('company_id', $companyIds))
            ->get();

        $total = 0;

        foreach ($branches as $branch) {
            $count = $service->forBranch($branch);
            $total += $count;

            if ($count > 0) {
                $this->line("Sucursal {$branch->id} (empresa {$branch->company_id}): {$count} productos.");
            }
        }

        $this->info("Saldos iniciales registrados: {$total}.");

        return self::SUCCESS;
    }
}
