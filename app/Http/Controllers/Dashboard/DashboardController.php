<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Provider;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\Services\Dashboard\BusinessHealthService;
use App\Services\SubscriptionGate;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /** Estados de un comprobante electrónico que todavía no termina su trámite en el SRI. */
    const SRI_PENDING_STATES = [
        VoucherStates::SAVED,
        VoucherStates::SIGNED,
        VoucherStates::SENDED,
        VoucherStates::RECEIVED,
        VoucherStates::IN_PROCESS,
    ];

    /** Estados de un comprobante electrónico rechazado por el SRI. */
    const SRI_REJECTED_STATES = [
        VoucherStates::RETURNED,
        VoucherStates::REJECTED,
    ];

    /** Días hacia atrás que cuenta la sección "Comprobantes en el SRI". */
    const SRI_WINDOW_DAYS = 30;

    public function index(): JsonResponse
    {
        $user = Auth::user();
        $company = $user->company;

        $active = true;
        $expired = null;
        $certExpiration = null;
        $income = [];
        $expenses = [];
        $countOrders = 0;
        $countShops = 0;
        $countCustomers = 0;
        $countProviders = 0;
        $newCustomersThisMonth = 0;
        $newProvidersThisMonth = 0;
        $recentOrders = [];
        $sri = [
            'pending' => 0,
            'rejected' => 0,
            'oldest_pending_date' => null,
            'by_type' => array_fill_keys(
                ['orders', 'shops', 'retentions', 'referralguides'],
                ['pending' => 0, 'rejected' => 0]
            ),
        ];

        $branch = $company
            ? Branch::where('company_id', $company->id)->orderBy('created_at')->first()
            : null;

        if ($branch) {
            $active = $company->active_voucher;
            $expired = $company->expired;
            $certExpiration = $company->sign_valid_to;

            $income = Order::selectRaw(
                "SUM(total) as total, DATE_FORMAT(date, '%m-%Y') AS name, DATE_FORMAT(date, '%Y%m') AS period"
            )
                ->groupBy('name', 'period')
                ->orderBy('period', 'desc')
                ->take(5)
                ->get()
                ->reverse()
                ->values();

            $expenses = Shop::selectRaw(
                "SUM(total) as total, DATE_FORMAT(date, '%m-%Y') AS name, DATE_FORMAT(date, '%Y%m') AS period"
            )
                ->groupBy('name', 'period')
                ->orderBy('period', 'desc')
                ->take(5)
                ->get()
                ->reverse()
                ->values();

            $countOrders = Order::count();
            $countShops = Shop::count();
            $countCustomers = Customer::where('type_identification', '<>', 'cf')->count();
            $countProviders = Provider::count();

            $startOfMonth = Carbon::now()->startOfMonth();
            $newCustomersThisMonth = Customer::where('type_identification', '<>', 'cf')
                ->where('created_at', '>=', $startOfMonth)
                ->count();
            $newProvidersThisMonth = Provider::where('created_at', '>=', $startOfMonth)->count();

            $recentOrders = Order::join('customers AS c', 'c.id', 'orders.customer_id')
                ->select('orders.id', 'orders.serie', 'orders.voucher_type_id', 'orders.date', 'orders.total', 'orders.state', 'c.name AS customer_name')
                ->latest('orders.created_at')
                ->take(5)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'serie' => $order->serie,
                    'voucher_type_id' => $order->voucher_type_id,
                    'date' => $order->date,
                    'total' => $order->total,
                    'state' => $order->state,
                    'customer' => ['name' => $order->customer_name],
                ]);

            $sri = $this->sriStatus();
        }

        return response()->json([
            'active' => $active,
            'expired' => $expired,
            'certExpiration' => $certExpiration,
            // Tiene .p12 cargado; `certExpiration` puede ser null aunque lo tenga (datos viejos sin fecha).
            'hasCert' => (bool) $company?->cert_dir,
            'income' => $income,
            'expenses' => $expenses,
            'counts' => [
                'orders' => $countOrders,
                'shops' => $countShops,
                'customers' => $countCustomers,
                'providers' => $countProviders,
            ],
            'newThisMonth' => [
                'customers' => $newCustomersThisMonth,
                'providers' => $newProvidersThisMonth,
            ],
            'recentOrders' => $recentOrders,
            'sri' => $sri,
        ], 200);
    }

    /**
     * "Salud de tu negocio": solo planes con financiero (GRATUITO/FINANCIERO).
     */
    public function health(SubscriptionGate $gate, BusinessHealthService $healthService): JsonResponse
    {
        $company = Auth::user()->company;
        $gate->ensureFinancieroAccess($company);

        return response()->json($healthService->summary($company));
    }

    /**
     * Conteo de comprobantes electrónicos de la empresa según su estado en el SRI:
     * ventas (factura y N/C), liquidaciones de compra, retenciones y guías.
     * `by_type` desglosa por listado del front para que cada número coincida
     * con su lista. Solo cuenta los emitidos desde `since` (hoy - 30 días).
     *
     * @return array{since: string, pending: int, rejected: int, oldest_pending_date: ?string, by_type: array<string, array{pending: int, rejected: int}>}
     */
    private function sriStatus(): array
    {
        $voucherTypeIds = VoucherType::whereIn('slug', [
            VoucherTypes::VENTA_FACTURA,
            VoucherTypes::VENTA_NOTA_CREDITO,
            VoucherTypes::COMPRA_LIQUIDACION_COMPRA,
        ])->pluck('id', 'slug');

        /** @var array<string, array{query: Builder<Model>, state: string, date: string}> $sources */
        $sources = [
            'orders' => [
                'query' => Order::whereIn('voucher_type_id', [
                    $voucherTypeIds[VoucherTypes::VENTA_FACTURA] ?? null,
                    $voucherTypeIds[VoucherTypes::VENTA_NOTA_CREDITO] ?? null,
                ]),
                'state' => 'state',
                'date' => 'date',
            ],
            'shops' => [
                'query' => Shop::where('voucher_type_id', $voucherTypeIds[VoucherTypes::COMPRA_LIQUIDACION_COMPRA] ?? null),
                'state' => 'state',
                'date' => 'date',
            ],
            'retentions' => ['query' => Shop::query(), 'state' => 'state_retencion', 'date' => 'date_retention'],
            'referralguides' => ['query' => ReferralGuide::query(), 'state' => 'state', 'date' => 'date'],
        ];

        $since = Carbon::today(config('app.timezone'))->subDays(self::SRI_WINDOW_DAYS)->toDateString();

        foreach ($sources as $type => $source) {
            $sources[$type]['query'] = $source['query']->whereDate($source['date'], '>=', $since);
        }

        $pending = 0;
        $rejected = 0;
        $oldestPendingDates = [];
        $byType = [];

        foreach ($sources as $type => $source) {
            $counts = (clone $source['query'])
                ->whereIn($source['state'], [...self::SRI_PENDING_STATES, ...self::SRI_REJECTED_STATES])
                ->groupBy($source['state'])
                ->toBase()
                ->select($source['state'].' AS voucher_state')
                ->selectRaw('COUNT(*) AS total')
                ->pluck('total', 'voucher_state');

            $byType[$type] = [
                'pending' => (int) $counts->only(self::SRI_PENDING_STATES)->sum(),
                'rejected' => (int) $counts->only(self::SRI_REJECTED_STATES)->sum(),
            ];
            $pending += $byType[$type]['pending'];
            $rejected += $byType[$type]['rejected'];

            $oldestPendingDate = $byType[$type]['pending'] > 0
                ? (clone $source['query'])->whereIn($source['state'], self::SRI_PENDING_STATES)->min($source['date'])
                : null;

            if ($oldestPendingDate !== null) {
                $oldestPendingDates[] = Carbon::parse($oldestPendingDate)->toDateString();
            }
        }

        return [
            'since' => $since,
            'pending' => $pending,
            'rejected' => $rejected,
            'oldest_pending_date' => $oldestPendingDates === [] ? null : min($oldestPendingDates),
            'by_type' => $byType,
        ];
    }
}
