<?php

namespace App\Services\Dashboard;

use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Holiday;
use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegisterSession;
use App\Models\VoucherType;
use App\StaticClasses\AccountsPayableMovementTypes;
use App\StaticClasses\AccountsReceivableMovementTypes;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Salud de tu negocio" (plan financiero): flujo de caja a 30 días,
 * antigüedad de la cartera por cobrar y apartado estimado para el SRI.
 *
 * Saldo pendiente por documento = total - paid (- notas de crédito aplicadas
 * en ventas, - retenciones en compras), igual criterio que "Cartera vencida".
 * Los saldos iniciales no tienen vencimiento: van en la semana 1 del flujo y
 * en el bucket `no_due` de la antigüedad.
 */
class BusinessHealthService
{
    /** Horizonte del flujo de caja, en días desde hoy. */
    const CASHFLOW_DAYS = 30;

    /** Código de impuesto SRI en `shop_retention_items.code`. */
    const RETENTION_RENT_CODE = 1;

    const RETENTION_IVA_CODE = 2;

    /** Día de declaración según el 9no dígito del RUC (calendario SRI). */
    const DUE_DAY_BY_RUC_DIGIT = [1 => 10, 2 => 12, 3 => 14, 4 => 16, 5 => 18, 6 => 20, 7 => 22, 8 => 24, 9 => 26, 0 => 28];

    private CarbonImmutable $today;

    public function __construct()
    {
        $this->today = CarbonImmutable::today();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Company $company): array
    {
        $receivables = $this->receivableDocuments();
        $payables = $this->payableDocuments();

        return [
            'cashflow' => $this->cashflow($receivables, $payables),
            'aging' => $this->aging($receivables),
            'sri_reserve' => $this->sriReserve($company),
        ];
    }

    /**
     * Ventas a crédito/anticipo con saldo pendiente + saldos iniciales.
     *
     * @return Collection<int, array{customer_id: int, amount: float, due_date: ?CarbonImmutable}>
     */
    private function receivableDocuments(): Collection
    {
        $creditNotes = AccountReceivableMovement::where('type', AccountsReceivableMovementTypes::NOTA_CREDITO)
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(haber) AS total')
            ->pluck('total', 'order_id');

        $documents = Order::whereIn('payment_type', [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO])
            ->where(fn ($query) => $query->whereNull('state')->orWhere('state', '<>', VoucherStates::CANCELED))
            ->whereColumn('paid', '<', 'total')
            ->get(['id', 'customer_id', 'total', 'paid', 'due_date'])
            ->toBase()
            ->map(fn (Order $order) => [
                'customer_id' => (int) $order->customer_id,
                'amount' => round((float) $order->total - (float) $order->paid - (float) ($creditNotes[$order->id] ?? 0), 2),
                'due_date' => $order->due_date ? CarbonImmutable::parse($order->due_date)->startOfDay() : null,
            ]);

        $openingBalances = AccountReceivableMovement::where('type', AccountsReceivableMovementTypes::SALDO_INICIAL)
            ->get(['customer_id', 'debe'])
            ->toBase()
            ->map(fn (AccountReceivableMovement $movement) => [
                'customer_id' => (int) $movement->customer_id,
                'amount' => round((float) $movement->debe, 2),
                'due_date' => null,
            ]);

        return $documents->concat($openingBalances)->filter(fn (array $document) => $document['amount'] > 0)->values();
    }

    /**
     * Compras a crédito/anticipo con saldo pendiente (menos retenciones) + saldos iniciales.
     *
     * @return Collection<int, array{amount: float, due_date: ?CarbonImmutable}>
     */
    private function payableDocuments(): Collection
    {
        $documents = Shop::whereIn('payment_type', [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO])
            ->whereColumn('paid', '<', 'total')
            ->withSum('shopretentionitems as retention_sum', 'value')
            ->get(['id', 'total', 'paid', 'due_date'])
            ->toBase()
            ->map(fn (Shop $shop) => [
                'amount' => round((float) $shop->total - (float) $shop->paid - (float) $shop->getAttribute('retention_sum'), 2),
                'due_date' => $shop->due_date ? CarbonImmutable::parse($shop->due_date)->startOfDay() : null,
            ]);

        $openingBalances = AccountPayableMovement::where('type', AccountsPayableMovementTypes::SALDO_INICIAL)
            ->get(['haber'])
            ->toBase()
            ->map(fn (AccountPayableMovement $movement) => [
                'amount' => round((float) $movement->haber, 2),
                'due_date' => null,
            ]);

        return $documents->concat($openingBalances)->filter(fn (array $document) => $document['amount'] > 0)->values();
    }

    /**
     * @param  Collection<int, array{customer_id: int, amount: float, due_date: ?CarbonImmutable}>  $receivables
     * @param  Collection<int, array{amount: float, due_date: ?CarbonImmutable}>  $payables
     * @return array<string, mixed>
     */
    private function cashflow(Collection $receivables, Collection $payables): array
    {
        $horizon = $this->today->addDays(self::CASHFLOW_DAYS);
        $inHorizon = fn (array $document) => $document['due_date'] === null || $document['due_date']->lte($horizon);

        $receivables = $receivables->filter($inHorizon);
        $payables = $payables->filter($inHorizon);

        $cash = (float) BankAccount::sum('balance')
            + (float) CashRegisterSession::where('status', CashRegisterStatuses::OPEN)->sum('current_balance');

        $receivable = round($receivables->sum('amount'), 2);
        $payable = round($payables->sum('amount'), 2);

        // 4 semanas; la última llega hasta el día 30. La primera incluye lo vencido y lo sin vencimiento.
        $weeks = collect([[0, 6], [7, 13], [14, 20], [21, self::CASHFLOW_DAYS]])
            ->map(function (array $range, int $index) use ($receivables, $payables) {
                $from = $this->today->addDays($range[0]);
                $to = $this->today->addDays($range[1]);
                $inWeek = fn (array $document) => $document['due_date'] === null
                    ? $index === 0
                    : $document['due_date']->lte($to) && ($index === 0 || $document['due_date']->gte($from));

                return [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'in' => round($receivables->filter($inWeek)->sum('amount'), 2),
                    'out' => round($payables->filter($inWeek)->sum('amount'), 2),
                ];
            });

        return [
            'cash' => round($cash, 2),
            'receivable' => $receivable,
            'payable' => $payable,
            'projected' => round($cash + $receivable - $payable, 2),
            'weeks' => $weeks->all(),
        ];
    }

    /**
     * @param  Collection<int, array{customer_id: int, amount: float, due_date: ?CarbonImmutable}>  $receivables
     * @return array<string, mixed>
     */
    private function aging(Collection $receivables): array
    {
        $bucketOf = function (array $document): string {
            if ($document['due_date'] === null) {
                return 'no_due';
            }

            $daysOverdue = (int) $document['due_date']->diffInDays($this->today, false);

            return match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => 'd1_30',
                $daysOverdue <= 60 => 'd31_60',
                $daysOverdue <= 90 => 'd61_90',
                default => 'd90_plus',
            };
        };

        $buckets = array_fill_keys(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'no_due'], 0.0);
        $counts = array_fill_keys(array_keys($buckets), 0);

        foreach ($receivables as $document) {
            $bucket = $bucketOf($document);
            $buckets[$bucket] += $document['amount'];
            $counts[$bucket]++;
        }

        // Por cobrar ya: lo vencido + saldos iniciales (sin vencimiento).
        $toCollect = $receivables
            ->filter(fn (array $document) => $document['due_date'] === null || $document['due_date']->lt($this->today))
            ->groupBy('customer_id')
            ->map(function (Collection $documents, int $customerId) {
                $oldestDueDate = $documents->pluck('due_date')->filter()->sort()->first();

                return [
                    'customer_id' => $customerId,
                    'amount' => round($documents->sum('amount'), 2),
                    'oldest_due_date' => $oldestDueDate?->toDateString(),
                    'days_overdue' => $oldestDueDate ? (int) $oldestDueDate->diffInDays($this->today) : null,
                ];
            })
            ->sortByDesc('amount')
            ->take(5)
            ->values();

        $names = Customer::whereIn('id', $toCollect->pluck('customer_id'))->pluck('name', 'id');

        return [
            'buckets' => array_map(fn (float $amount) => round($amount, 2), $buckets),
            'counts' => $counts,
            'to_collect' => $toCollect->map(fn (array $row) => [
                'customer_id' => $row['customer_id'],
                'name' => $names[$row['customer_id']] ?? null,
                'amount' => $row['amount'],
                'oldest_due_date' => $row['oldest_due_date'],
                'days_overdue' => $row['days_overdue'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sriReserve(Company $company): array
    {
        if ($company->isNegocioPopular()) {
            return [
                'regime' => 'rimpe_popular',
                'frequency' => null,
                'period_from' => null,
                'period_to' => null,
                'iva_sales' => null,
                'iva_purchases' => null,
                'iva_withheld' => null,
                'iva_retained' => null,
                'rent_retained' => null,
                'estimated' => null,
                'purchases_count' => null,
                'due_date' => null,
            ];
        }

        // RIMPE emprendedor declara IVA semestral (ene-jun / jul-dic).
        $semiannual = (int) $company->rimpe === 1;
        $periodFrom = $semiannual
            ? $this->today->month($this->today->month <= 6 ? 1 : 7)->startOfMonth()
            : $this->today->startOfMonth();
        $periodTo = $semiannual ? $periodFrom->addMonths(5)->endOfMonth() : $periodFrom->endOfMonth();
        $period = [$periodFrom->toDateString(), $periodTo->toDateString()];

        $voucherTypeIds = VoucherType::pluck('id', 'slug');
        $ivaColumns = 'COALESCE(SUM(iva + iva5 + iva8 + iva15), 0)';

        $authorizedSales = fn (string $slug) => Order::where('voucher_type_id', $voucherTypeIds[$slug] ?? null)
            ->where('state', VoucherStates::AUTHORIZED)
            ->whereBetween('date', $period);

        $ivaSales = (float) $authorizedSales(VoucherTypes::VENTA_FACTURA)->selectRaw("{$ivaColumns} AS total")->value('total')
            - (float) $authorizedSales(VoucherTypes::VENTA_NOTA_CREDITO)->selectRaw("{$ivaColumns} AS total")->value('total');
        $ivaWithheld = (float) $authorizedSales(VoucherTypes::VENTA_FACTURA)->sum('iva_retention');

        $purchases = Shop::whereIn('voucher_type_id', [
            $voucherTypeIds[VoucherTypes::COMPRA_FACTURA] ?? null,
            $voucherTypeIds[VoucherTypes::COMPRA_LIQUIDACION_COMPRA] ?? null,
            $voucherTypeIds[VoucherTypes::COMPRA_NOTA_DEBITO] ?? null,
        ])
            ->where(fn ($query) => $query->whereNull('state')->orWhere('state', '<>', VoucherStates::CANCELED))
            ->whereBetween('date', $period);

        $ivaPurchases = (float) (clone $purchases)->selectRaw('COALESCE(SUM(iva + iva5 + iva15), 0) AS total')->value('total');
        $purchasesCount = (clone $purchases)->count();

        $retentions = Shop::whereNotNull('serie_retencion')
            ->where(fn ($query) => $query->whereNull('state_retencion')->orWhere('state_retencion', '<>', VoucherStates::CANCELED))
            ->whereBetween('date_retention', $period)
            ->join('shop_retention_items AS sri', 'sri.shop_id', 'shops.id')
            ->groupBy('sri.code')
            ->selectRaw('sri.code, SUM(sri.value) AS total')
            ->pluck('total', 'sri.code');

        $ivaRetained = (float) ($retentions[self::RETENTION_IVA_CODE] ?? 0);

        return [
            'regime' => $semiannual ? 'rimpe_emprendedor' : 'general',
            'frequency' => $semiannual ? 'semiannual' : 'monthly',
            'period_from' => $period[0],
            'period_to' => $period[1],
            'iva_sales' => round($ivaSales, 2),
            'iva_purchases' => round($ivaPurchases, 2),
            'iva_withheld' => round($ivaWithheld, 2),
            'iva_retained' => round($ivaRetained, 2),
            'rent_retained' => round((float) ($retentions[self::RETENTION_RENT_CODE] ?? 0), 2),
            'estimated' => round(max(0, $ivaSales - $ivaPurchases - $ivaWithheld) + $ivaRetained, 2),
            'purchases_count' => $purchasesCount,
            'due_date' => $this->declarationDueDate($company->ruc, $periodTo)->toDateString(),
        ];
    }

    /**
     * Vence en el mes siguiente al período, el día que toca por el 9no dígito
     * del RUC; si cae fin de semana o feriado (`holidays`) pasa al siguiente
     * día hábil.
     */
    public function declarationDueDate(string $ruc, CarbonImmutable $periodTo): CarbonImmutable
    {
        $digit = (int) substr($ruc, 8, 1);
        $dueDate = $periodTo->addDay()->day(self::DUE_DAY_BY_RUC_DIGIT[$digit]);

        while ($dueDate->isWeekend() || Holiday::covering($dueDate)->exists()) {
            $dueDate = $dueDate->addDay();
        }

        return $dueDate;
    }
}
