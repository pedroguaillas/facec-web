<?php

namespace App\Services\Dashboard;

use App\Models\VoucherType;
use App\Services\CompanyService;
use App\StaticClasses\CompanyActivityStatuses;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tablero del admin central: comprobantes electrónicos, contribuyentes,
 * suscripciones y firmas de TODO el sistema. Todo con consultas agregadas
 * (DB::table, sin BranchScope) y cacheado CACHE_SECONDS.
 */
class AdminDashboardService
{
    const CACHE_KEY = 'admin.dashboard';

    const CACHE_SECONDS = 300;

    /** Un comprobante pendiente más viejo que esto se considera atascado. */
    const STUCK_HOURS = 24;

    /** Solo se miran los atascados creados en esta ventana (los más viejos ya no se reintentan). */
    const STUCK_WINDOW_DAYS = 30;

    /** Días hacia adelante para renovaciones y firmas por vencer. */
    const UPCOMING_DAYS = 30;

    /** Enviado o por enviar al SRI, sin respuesta final. */
    const PENDING_STATES = [
        VoucherStates::SAVED,
        VoucherStates::SIGNED,
        VoucherStates::SENDED,
        VoucherStates::RECEIVED,
        VoucherStates::RETURNED,
        VoucherStates::IN_PROCESS,
    ];

    /** @var Collection<string, int>|null Ids por slug de los tipos electrónicos con tabla propia. */
    private ?Collection $voucherTypeIds = null;

    public function __construct(private CompanyService $companies) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => [
            'vouchers' => $this->vouchers(),
            'companies' => $this->companiesSummary(),
            'subscriptions' => $this->subscriptions(),
            'signatures' => $this->signatures(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function vouchers(): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $daysStart = $today->subDays(29);
        $from = $monthStart->min($daysStart)->toDateString();

        // Una pasada: conteo por fecha de emisión y estado desde el inicio del mes o de los 30 días.
        $byDateState = DB::query()
            ->fromSub($this->electronicVouchers(fn (Builder $query, array $columns) => $query
                ->selectRaw("{$columns['date']} AS date, {$columns['state']} AS state")
                ->whereRaw("{$columns['date']} >= ?", [$from])
                ->whereIn(DB::raw($columns['state']), [VoucherStates::AUTHORIZED, VoucherStates::REJECTED, ...self::PENDING_STATES])), 'v')
            ->groupBy('date', 'state')
            ->selectRaw('date, state, COUNT(*) AS total')
            ->get()
            ->map(fn ($row) => (object) ['date' => substr((string) $row->date, 0, 10), 'state' => $row->state, 'total' => (int) $row->total]);

        $month = $byDateState->where('date', '>=', $monthStart->toDateString());
        $daily = $byDateState->groupBy('date');

        $stuckByBranch = DB::query()
            ->fromSub($this->electronicVouchers(fn (Builder $query, array $columns) => $query
                ->selectRaw("{$columns['branch_id']} AS branch_id, {$columns['created_at']} AS created_at")
                ->whereIn(DB::raw($columns['state']), self::PENDING_STATES)
                ->whereRaw("{$columns['created_at']} < ?", [now()->subHours(self::STUCK_HOURS)])
                ->whereRaw("{$columns['created_at']} >= ?", [now()->subDays(self::STUCK_WINDOW_DAYS)])), 'v')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS pending, MIN(created_at) AS oldest_at')
            ->get();

        return [
            'month' => [
                'authorized' => $month->where('state', VoucherStates::AUTHORIZED)->sum('total'),
                'pending' => $month->whereIn('state', self::PENDING_STATES)->sum('total'),
                'rejected' => $month->where('state', VoucherStates::REJECTED)->sum('total'),
            ],
            'stuck' => (int) $stuckByBranch->sum('pending'),
            'by_day' => collect(range(0, 29))->map(function (int $offset) use ($daysStart, $daily) {
                $date = $daysStart->addDays($offset)->toDateString();
                $rows = $daily->get($date, collect());

                return [
                    'date' => $date,
                    'authorized' => $rows->where('state', VoucherStates::AUTHORIZED)->sum('total'),
                    'rejected' => $rows->where('state', VoucherStates::REJECTED)->sum('total'),
                ];
            })->all(),
            'top_errors' => $this->topErrors($monthStart),
            'stuck_companies' => $this->stuckCompanies($stuckByBranch),
        ];
    }

    /**
     * Top 5 de motivos de rechazo del mes (`extra_detail`, primeros 255 caracteres).
     *
     * @return array<int, array{message: string, count: int}>
     */
    private function topErrors(CarbonImmutable $monthStart): array
    {
        return DB::query()
            ->fromSub($this->electronicVouchers(fn (Builder $query, array $columns) => $query
                ->selectRaw("LEFT(COALESCE({$columns['detail']}, ''), 255) AS message")
                ->where(DB::raw($columns['state']), VoucherStates::REJECTED)
                ->whereRaw("{$columns['date']} >= ?", [$monthStart->toDateString()])), 'v')
            ->groupBy('message')
            ->selectRaw('message, COUNT(*) AS total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['message' => $row->message === '' ? 'Sin detalle' : $row->message, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * Top 10 de empresas con más comprobantes atascados.
     *
     * @param  Collection<int, \stdClass>  $stuckByBranch
     * @return array<int, array{id: int, ruc: string, company: string, pending: int, oldest_at: string}>
     */
    private function stuckCompanies(Collection $stuckByBranch): array
    {
        $companyOfBranch = DB::table('branches')->whereIn('id', $stuckByBranch->pluck('branch_id'))->pluck('company_id', 'id');
        $byCompany = $stuckByBranch->groupBy(fn (\stdClass $row): int => (int) ($companyOfBranch[$row->branch_id] ?? 0))
            ->map(fn ($rows) => ['pending' => (int) $rows->sum('pending'), 'oldest_at' => $rows->min('oldest_at')])
            ->sortByDesc('pending')
            ->take(10);
        $companies = DB::table('companies')->whereIn('id', $byCompany->keys())->get(['id', 'ruc', 'company'])->keyBy('id');

        return $byCompany
            ->filter(fn ($row, $companyId) => $companies->has($companyId))
            ->map(fn ($row, $companyId) => [
                'id' => (int) $companyId,
                'ruc' => $companies[$companyId]->ruc,
                'company' => $companies[$companyId]->company,
                'pending' => $row['pending'],
                'oldest_at' => CarbonImmutable::parse($row['oldest_at'])->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Comprobantes electrónicos de todas las empresas como un UNION ALL:
     * factura y nota de crédito (orders), liquidación (shops.state),
     * retención (shops.state_retencion) y guía de remisión. `$select` recibe
     * cada rama y sus columnas equivalentes (expresiones SQL fijas) y la
     * proyecta/filtra igual en todas.
     *
     * @param  callable(Builder, array{branch_id: literal-string, date: literal-string, state: literal-string, detail: literal-string, created_at: literal-string}): Builder  $select
     */
    private function electronicVouchers(callable $select): Builder
    {
        $typeIds = $this->voucherTypeIds ??= VoucherType::whereIn('slug', [VoucherTypes::VENTA_FACTURA, VoucherTypes::VENTA_NOTA_CREDITO, VoucherTypes::COMPRA_LIQUIDACION_COMPRA])->pluck('id', 'slug');
        $settlementTypeId = $typeIds[VoucherTypes::COMPRA_LIQUIDACION_COMPRA] ?? null;
        $electronicSaleTypeIds = $typeIds->except(VoucherTypes::COMPRA_LIQUIDACION_COMPRA)->values();

        $branches = [
            $select(DB::table('orders')->whereIn('voucher_type_id', $electronicSaleTypeIds), $this->columns('orders')),
            $select(DB::table('shops')->where('voucher_type_id', $settlementTypeId), $this->columns('shops')),
            $select(DB::table('shops')->whereNotNull('state_retencion'), [
                ...$this->columns('shops'),
                'date' => 'COALESCE(shops.date_retention, shops.date)',
                'state' => 'shops.state_retencion',
                'detail' => 'shops.extra_detail_retention',
            ]),
            $select(DB::table('referral_guides'), $this->columns('referral_guides')),
        ];

        return array_reduce(array_slice($branches, 1), fn (Builder $union, Builder $branch) => $union->unionAll($branch), $branches[0]);
    }

    /**
     * @param  literal-string  $table
     * @return array{branch_id: literal-string, date: literal-string, state: literal-string, detail: literal-string, created_at: literal-string}
     */
    private function columns(string $table): array
    {
        return [
            'branch_id' => "{$table}.branch_id",
            'date' => "{$table}.date",
            'state' => "{$table}.state",
            'detail' => "{$table}.extra_detail",
            'created_at' => "{$table}.created_at",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companiesSummary(): array
    {
        $monthStart = CarbonImmutable::today()->startOfMonth();
        $firstMonth = $monthStart->subMonths(5);
        $companies = DB::table('companies')->whereNull('deleted_at');

        $byMonth = (clone $companies)
            ->where('created_at', '>=', $firstMonth)
            ->groupBy('month')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->pluck('total', 'month');

        $activity = $this->companies->activityStats();
        $total = (clone $companies)->count();
        $activeIds = (clone $companies)->pluck('id')->flip();
        $statusCounts = $activity->filter(fn ($row, $companyId) => $activeIds->has($companyId))->countBy('activity_status');
        $tracked = $statusCounts->except(CompanyActivityStatuses::NEVER)->sum();

        return [
            'total' => $total,
            'new_month' => (int) ($byMonth[$monthStart->format('Y-m')] ?? 0),
            'new_prev_month' => (int) ($byMonth[$monthStart->subMonth()->format('Y-m')] ?? 0),
            'by_month' => collect(range(0, 5))->map(function (int $offset) use ($firstMonth, $byMonth) {
                $month = $firstMonth->addMonths($offset)->format('Y-m');

                return ['month' => $month, 'count' => (int) ($byMonth[$month] ?? 0)];
            })->all(),
            'activity' => [
                CompanyActivityStatuses::ACTIVE => (int) $statusCounts->get(CompanyActivityStatuses::ACTIVE, 0),
                CompanyActivityStatuses::RISK => (int) $statusCounts->get(CompanyActivityStatuses::RISK, 0),
                CompanyActivityStatuses::INACTIVE => (int) $statusCounts->get(CompanyActivityStatuses::INACTIVE, 0),
                // Las que no aparecen en las estadísticas tampoco emitieron.
                CompanyActivityStatuses::NEVER => $total - $tracked,
            ],
        ];
    }

    /**
     * Vigente = la última suscripción (mayor id) con hoy entre `started_at`
     * y `ended_at`, como Company::subscription(). Renovaciones y vencidas
     * miran la última suscripción de cada empresa: si ya renovó, no aparece.
     *
     * @return array<string, mixed>
     */
    private function subscriptions(): array
    {
        $today = CarbonImmutable::today();
        $companies = fn () => DB::table('companies')->whereNull('deleted_at')->select('id');

        $current = DB::table('subscriptions')
            ->whereIn('id', DB::table('subscriptions')
                ->whereDate('started_at', '<=', $today)
                ->whereDate('ended_at', '>=', $today)
                ->whereIn('company_id', $companies())
                ->groupBy('company_id')
                ->selectRaw('MAX(id)'))
            ->groupBy('plan')
            ->selectRaw('plan, COUNT(*) AS total')
            ->orderByDesc('total')
            ->get();

        $latest = fn () => DB::table('subscriptions AS s')
            ->join('companies AS c', 'c.id', 's.company_id')
            ->whereNull('c.deleted_at')
            ->whereIn('s.id', DB::table('subscriptions')->groupBy('company_id')->selectRaw('MAX(id)'));

        return [
            'by_plan' => $current->map(fn ($row) => ['plan' => $row->plan, 'count' => (int) $row->total])->all(),
            'renewals' => $latest()
                ->whereDate('s.ended_at', '>=', $today)
                ->whereDate('s.ended_at', '<=', $today->addDays(self::UPCOMING_DAYS))
                ->orderBy('s.ended_at')
                ->get(['c.id', 'c.ruc', 'c.company', 's.plan', 's.ended_at'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'ruc' => $row->ruc,
                    'company' => $row->company,
                    'plan' => $row->plan,
                    'ended_at' => substr((string) $row->ended_at, 0, 10),
                    'days_left' => (int) $today->diffInDays(CarbonImmutable::parse($row->ended_at)->startOfDay()),
                ])
                ->all(),
            'expired_recent' => $latest()
                ->whereDate('s.ended_at', '<', $today)
                ->whereDate('s.ended_at', '>=', $today->subDays(self::UPCOMING_DAYS))
                ->count(),
            'revenue_month' => round((float) DB::table('subscriptions')
                ->where('created_at', '>=', $today->startOfMonth())
                ->where('is_trial', false)
                ->sum('amount'), 2),
        ];
    }

    /**
     * Firma electrónica (`sign_valid_to`) de empresas no eliminadas.
     *
     * @return array<string, mixed>
     */
    private function signatures(): array
    {
        $now = now();
        $companies = fn () => DB::table('companies')->whereNull('deleted_at')->whereNotNull('sign_valid_to');

        return [
            'expired' => $companies()->where('sign_valid_to', '<', $now)->count(),
            'expiring' => $companies()
                ->whereBetween('sign_valid_to', [$now, $now->copy()->addDays(self::UPCOMING_DAYS)])
                ->orderBy('sign_valid_to')
                ->get(['id', 'ruc', 'company', 'sign_valid_to'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'ruc' => $row->ruc,
                    'company' => $row->company,
                    'sign_valid_to' => CarbonImmutable::parse($row->sign_valid_to)->toIso8601String(),
                    'days_left' => (int) $now->diffInDays(CarbonImmutable::parse($row->sign_valid_to)),
                ])
                ->all(),
        ];
    }
}
