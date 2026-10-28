<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Product\OpeningBalanceFromStockService;
use App\StaticClasses\CompanyActivityStatuses;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(private SriResolveNameService $sriService) {}

    public function searchByIdentificationSRI(string $identification)
    {
        return $this->sriService->searchByIdentificationSRI($identification);
    }

    /**
     * Listado del admin con la actividad de cada contribuyente: último uso
     * de token de sus usuarios, comprobantes de los últimos 30 días (sin
     * anulados ni proformas), fecha del primero y `activity_status`
     * (CompanyActivityStatuses). La actividad sale de `activityStats()` (3
     * consultas agrupadas para todo el sistema), así el filtro por estado
     * pagina bien sin calcular subconsultas por fila.
     */
    public function getAll(?string $search = null, int $perPage = 15, ?string $activity = null)
    {
        $stats = $this->activityStats();

        $companies = Company::query()
            ->with(['lastSubscription', 'user'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('ruc', 'LIKE', "%$search%")
                        ->orWhere('company', 'LIKE', "%$search%");
                });
            })
            ->when($activity, fn ($query, $activity) => $activity === CompanyActivityStatuses::NEVER
                // Sin comprobantes no aparece en las estadísticas: se excluyen las que sí tienen.
                ? $query->whereNotIn('id', $stats->reject(fn (array $row) => $row['activity_status'] === CompanyActivityStatuses::NEVER)->keys())
                : $query->whereIn('id', $stats->where('activity_status', $activity)->keys()))
            ->orderBy('id', 'DESC')
            ->paginate($perPage);

        $companies->getCollection()->each(function (Company $company) use ($stats) {
            // hasOneThrough agrega la columna técnica `laravel_through_key`; no es parte del contrato.
            $company->user?->makeHidden('laravel_through_key');

            foreach ($stats->get($company->id, $this->emptyActivity()) as $key => $value) {
                $company->setAttribute($key, $value);
            }
        });

        return $companies;
    }

    /**
     * Actividad de cada empresa que tuvo algún comprobante o acceso, por id
     * (las que no están son `never` sin acceso). Una pasada agrupada por
     * sucursal sobre ventas y otra sobre compras (índices `*_activity_index`
     * las cubren sin leer las tablas) y una por empresa sobre los tokens.
     *
     * @return Collection<int, array{last_activity_at: string|null, orders_30d: int, shops_30d: int, first_voucher_at: string|null, activity_status: string}>
     */
    public function activityStats(): Collection
    {
        $since30 = Carbon::today()->subDays(30)->toDateString();
        $proformaId = VoucherType::where('slug', VoucherTypes::VENTA_PROFORMA)->value('id');
        $companyOfBranch = DB::table('branches')->pluck('company_id', 'id');

        $orders = DB::table('orders')
            ->where('state', '!=', VoucherStates::CANCELED)
            ->when($proformaId, fn ($query) => $query->where('voucher_type_id', '!=', $proformaId))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, MIN(date) AS first_at, SUM(date >= ?) AS last_30d', [$since30])
            ->get();
        $shops = DB::table('shops')
            ->where(fn ($query) => $query->whereNull('state')->orWhere('state', '!=', VoucherStates::CANCELED))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, MIN(date) AS first_at, SUM(date >= ?) AS last_30d', [$since30])
            ->get();
        $lastActivity = DB::table('company_users AS cu')
            ->join('personal_access_tokens AS pat', function ($join) {
                $join->on('pat.tokenable_id', 'cu.user_id')->where('pat.tokenable_type', (new User)->getMorphClass());
            })
            ->groupBy('cu.level_id')
            ->selectRaw('cu.level_id AS company_id, MAX(pat.last_used_at) AS last_used_at')
            ->pluck('last_used_at', 'company_id');

        $vouchersByCompany = fn ($rows) => $rows->groupBy(fn ($row) => $companyOfBranch[$row->branch_id] ?? 0)
            ->map(fn ($branches) => ['first_at' => $branches->min('first_at'), 'last_30d' => (int) $branches->sum('last_30d')]);
        $orderStats = $vouchersByCompany($orders);
        $shopStats = $vouchersByCompany($shops);

        return $orderStats->keys()->merge($shopStats->keys())->merge($lastActivity->keys())->unique()
            ->mapWithKeys(function ($companyId) use ($orderStats, $shopStats, $lastActivity) {
                $lastUsedAt = $lastActivity[$companyId] ?? null;
                $firstVoucherAt = collect([$orderStats[$companyId]['first_at'] ?? null, $shopStats[$companyId]['first_at'] ?? null])->filter()->min();
                $row = [
                    'last_activity_at' => $lastUsedAt ? Carbon::parse($lastUsedAt)->toIso8601String() : null,
                    'orders_30d' => $orderStats[$companyId]['last_30d'] ?? 0,
                    'shops_30d' => $shopStats[$companyId]['last_30d'] ?? 0,
                    'first_voucher_at' => $firstVoucherAt,
                ];

                return [(int) $companyId => [...$row, 'activity_status' => $this->activityStatus($row, $lastUsedAt ? Carbon::parse($lastUsedAt) : null)]];
            });
    }

    /**
     * @param  array{orders_30d: int, shops_30d: int, first_voucher_at: string|null}  $row
     */
    private function activityStatus(array $row, ?Carbon $lastUsedAt): string
    {
        return match (true) {
            $row['first_voucher_at'] === null => CompanyActivityStatuses::NEVER,
            $lastUsedAt === null || $lastUsedAt->lt(Carbon::now()->subDays(30)) => CompanyActivityStatuses::INACTIVE,
            $lastUsedAt->lt(Carbon::now()->subDays(7)) || $row['orders_30d'] + $row['shops_30d'] === 0 => CompanyActivityStatuses::RISK,
            default => CompanyActivityStatuses::ACTIVE,
        };
    }

    /**
     * @return array{last_activity_at: null, orders_30d: int, shops_30d: int, first_voucher_at: null, activity_status: string}
     */
    private function emptyActivity(): array
    {
        return ['last_activity_at' => null, 'orders_30d' => 0, 'shops_30d' => 0, 'first_voucher_at' => null, 'activity_status' => CompanyActivityStatuses::NEVER];
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
