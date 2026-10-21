<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product\Product;
use App\Services\Product\OpeningBalanceFromStockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(private SriResolveNameService $sriService) {}

    public function searchByIdentificationSRI(string $identification)
    {
        return $this->sriService->searchByIdentificationSRI($identification);
    }

    public function getAll(?string $search = null, int $perPage = 15)
    {
        $companies = Company::query()
            ->with(['lastSubscription', 'user'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ruc', 'LIKE', "%$search%")
                        ->orWhere('company', 'LIKE', "%$search%");
                });
            })
            ->orderBy('id', 'DESC')
            ->paginate($perPage);

        // hasOneThrough agrega la columna técnica `laravel_through_key`; no es parte del contrato.
        $companies->getCollection()->each(fn (Company $company) => $company->user?->makeHidden('laravel_through_key'));

        return $companies;
    }

    /**
     * Si la empresa pasa a RIMPE Negocio Popular, todos sus productos (de
     * todas las sucursales) pasan a IVA 0% en la misma transacción. La
     * confirmación del usuario la exige `CompanyUpdateRequest`. Si activa el
     * inventario, el `stock` actual de sus productos se carga como saldo inicial.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Company $company, array $attributes): Company
    {
        unset($attributes['confirm_iva_change']);

        $becomesNegocioPopular = $this->becomesNegocioPopular($company, $attributes['rimpe'] ?? $company->rimpe);
        $enablesInventory = ! $company->inventory && filter_var($attributes['inventory'] ?? false, FILTER_VALIDATE_BOOLEAN);

        DB::transaction(function () use ($company, $attributes, $becomesNegocioPopular, $enablesInventory) {
            $company->update($attributes);

            if ($becomesNegocioPopular) {
                $this->productsOutsideNegocioPopularIva($company)
                    ->update(['iva' => Company::NEGOCIO_POPULAR_IVA_CODES[0]]);
            }

            // El stock que se venía tipeando a mano pasa a ser el saldo inicial del kardex.
            if ($enablesInventory) {
                $company->branches->each(fn (Branch $branch) => app(OpeningBalanceFromStockService::class)->forBranch($branch));
            }
        });

        return $company->fresh();
    }

    /**
     * La empresa no es Negocio Popular hoy y el nuevo régimen sí lo es.
     */
    public function becomesNegocioPopular(Company $company, mixed $newRimpe): bool
    {
        return ! $company->isNegocioPopular()
            && (int) $newRimpe === Company::RIMPE_NEGOCIO_POPULAR;
    }

    /**
     * Productos que cambiarían a IVA 0% al pasar a Negocio Popular.
     */
    public function countProductsOutsideNegocioPopularIva(Company $company): int
    {
        return $this->productsOutsideNegocioPopularIva($company)->count();
    }

    /**
     * @return Builder<Product>
     */
    private function productsOutsideNegocioPopularIva(Company $company): Builder
    {
        return Product::withoutGlobalScope('branch')
            ->whereIn('branch_id', $company->branches()->select('id'))
            ->whereNotIn('iva', Company::NEGOCIO_POPULAR_IVA_CODES);
    }

    public function create(array $attributes)
    {
        return Company::create($attributes);
    }

    public function findByAttributes(array $attributes)
    {
        return Company::where($attributes)->firstOrFail();
    }
}
