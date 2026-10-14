<?php

namespace App\Services\RecurringInvoice;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Order\Order;
use App\Models\Order\RecurringInvoice;
use App\Models\Order\RecurringInvoiceRun;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegister;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Order\OrderStoreService;
use App\Services\SubscriptionGate;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\RecurringInvoiceStatuses;
use App\StaticClasses\RecurringRunStatuses;
use App\StaticClasses\VoucherTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Plantillas de factura recurrente y su emisión. La emisión arma el mismo
 * payload que el front manda a `orders.store` y usa OrderStoreService, así
 * que aplica sola las reglas de una venta normal (secuencial, IVA
 * diferenciado, cartera, tesorería, inventario, envío al SRI).
 *
 * Período: cada emisión corresponde a una fecha del calendario
 * (RecurringSchedule) y queda en `recurring_invoice_runs`, único por
 * plantilla + período — nunca se factura dos veces el mismo período. Si el
 * servidor estuvo caído se emite solo el último período vencido, con fecha
 * de hoy (no se emiten facturas atrasadas). Si falla, el período queda en
 * ERROR y se reintenta cada hora.
 */
class RecurringInvoiceService
{
    /** Marcadores reemplazados en descripción, info adicional y código/descripción de cada línea. */
    private const PLACEHOLDERS = ['{mes}', '{mm}', '{año}', '{periodo}', '{fecha}'];

    /** Límites del SRI para la línea (codigoPrincipal / descripcion). */
    public const ITEM_CODE_MAX = 25;

    public const ITEM_DESCRIPTION_MAX = 300;

    private const MONTH_NAMES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct(
        private RecurringSchedule $schedule,
        private SubscriptionGate $gate,
    ) {}

    /**
     * Crea o actualiza la plantilla y sus ítems.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?RecurringInvoice $recurring = null): RecurringInvoice
    {
        return DB::transaction(function () use ($data, $recurring) {
            $attributes = collect($data)->except('products')->all();

            if (($attributes['payment_type'] ?? null) === PaymentTypes::CREDITO) {
                $attributes['source_type'] = null;
                $attributes['source_id'] = null;
            } else {
                $attributes['due_days'] = null;
            }

            if (! $recurring) {
                $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

                $recurring = new RecurringInvoice([
                    'branch_id' => $branch->id,
                    'user_id' => Auth::id(),
                    'status' => RecurringInvoiceStatuses::ACTIVE,
                ]);
            }

            $recurring->fill($attributes);

            $scheduleChanged = ! $recurring->exists || $recurring->isDirty(['start_at', 'frequency', 'end_date', 'max_occurrences']);

            if ($scheduleChanged && $recurring->status !== RecurringInvoiceStatuses::PAUSED) {
                $this->reschedule($recurring);
            }

            $recurring->save();

            $recurring->items()->delete();
            $recurring->items()->createMany(array_map(fn (array $product) => [
                'product_id' => $product['product_id'],
                'code' => $product['code'] ?? null,
                'description' => $product['description'] ?? null,
                'quantity' => $product['quantity'],
                'price' => $product['price'],
                'discount' => $product['discount'] ?? 0,
                'iva' => $product['iva'],
            ], $data['products']));

            return $recurring->fresh(['items']);
        });
    }

    public function pause(RecurringInvoice $recurring): RecurringInvoice
    {
        if ($recurring->status !== RecurringInvoiceStatuses::ACTIVE) {
            throw new RuntimeException('Solo se puede pausar una factura recurrente activa.');
        }

        $recurring->update(['status' => RecurringInvoiceStatuses::PAUSED]);

        return $recurring;
    }

    /**
     * Reanuda desde hoy: los períodos que pasaron mientras estuvo pausada no
     * se emiten.
     */
    public function resume(RecurringInvoice $recurring): RecurringInvoice
    {
        if ($recurring->status !== RecurringInvoiceStatuses::PAUSED) {
            throw new RuntimeException('Solo se puede reanudar una factura recurrente pausada.');
        }

        $recurring->status = RecurringInvoiceStatuses::ACTIVE;
        $this->reschedule($recurring);
        $recurring->save();

        return $recurring;
    }

    /**
     * Emite el período que toca: el último vencido si `next_run_at` ya
     * llegó, o el próximo por adelantado ("Emitir ahora"). Corre como
     * `$actingUser` (el usuario de la petición) o, desde la cola, como el
     * creador de la plantilla.
     */
    public function emit(int $recurringInvoiceId, ?User $actingUser = null): Order
    {
        $recurring = RecurringInvoice::withoutGlobalScope('branch')->findOrFail($recurringInvoiceId);
        $user = $actingUser ?? $this->resolveUser($recurring);

        $previousUser = Auth::user();
        Auth::setUser($user);

        try {
            return $this->emitAsCurrentUser($recurring);
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
        }
    }

    private function emitAsCurrentUser(RecurringInvoice $recurring): Order
    {
        if ($recurring->status !== RecurringInvoiceStatuses::ACTIVE || ! $recurring->next_run_at) {
            throw new RuntimeException('La factura recurrente no está activa.');
        }

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $period = $recurring->next_run_at->lte($now)
            ? $this->schedule->lastOnOrBefore($recurring->start_at, $recurring->frequency, $now)
            : CarbonImmutable::parse($recurring->next_run_at);

        $run = RecurringInvoiceRun::firstOrNew([
            'recurring_invoice_id' => $recurring->id,
            'period_date' => $period->toDateString(),
        ]);

        if ($run->exists && $run->status === RecurringRunStatuses::ISSUED) {
            throw new RuntimeException('Este período ya fue emitido.');
        }

        try {
            $company = Auth::user()->company;
            $this->gate->ensureActiveSubscription($company);

            return DB::transaction(function () use ($recurring, $run, $company, $now, $today, $period) {
                $order = app(OrderStoreService::class)->createOrder(
                    $this->orderData($recurring, $company, $today, $period),
                );

                $run->fill([
                    'order_id' => $order->id,
                    'status' => RecurringRunStatuses::ISSUED,
                    'message' => null,
                    'attempts' => $run->attempts + 1,
                ])->save();

                $recurring->emitted_count++;
                $recurring->last_run_at = now();
                $recurring->last_error = null;
                $this->reschedule($recurring, after: $period->max($now));
                $recurring->save();

                return $order;
            });
        } catch (Throwable $e) {
            // Fuera de la transacción revertida: el $run/$recurring en memoria
            // pueden traer cambios que nunca llegaron a la BD.
            $message = mb_substr($e->getMessage(), 0, 500);
            $keys = ['recurring_invoice_id' => $recurring->id, 'period_date' => $period->toDateString()];
            $attempts = (int) RecurringInvoiceRun::where($keys)->value('attempts');

            RecurringInvoiceRun::updateOrCreate($keys, [
                'status' => RecurringRunStatuses::FAILED,
                'message' => $message,
                'attempts' => $attempts + 1,
            ]);

            RecurringInvoice::withoutGlobalScope('branch')
                ->whereKey($recurring->id)
                ->update(['last_run_at' => now(), 'last_error' => $message]);

            throw $e;
        }
    }

    /**
     * Próxima emisión que no esté ya emitida: la primera después de `$after`
     * (tras emitir) o, al crear/reanudar, la primera desde el inicio de hoy
     * (una hora de hoy ya pasada se emite en la próxima corrida; los días
     * anteriores no). FINALIZADA si se pasa de la fecha fin o del número de
     * emisiones.
     */
    private function reschedule(RecurringInvoice $recurring, ?CarbonImmutable $after = null): void
    {
        $next = $after
            ? $this->schedule->firstAfter($recurring->start_at, $recurring->frequency, $after)
            : $this->schedule->firstOnOrAfter($recurring->start_at, $recurring->frequency, CarbonImmutable::today());

        while ($next && $recurring->exists && $recurring->runs()
            ->whereDate('period_date', $next->toDateString())
            ->where('status', RecurringRunStatuses::ISSUED)
            ->exists()) {
            $next = $this->schedule->firstAfter($recurring->start_at, $recurring->frequency, $next);
        }

        $exhausted = $next === null
            || ($recurring->max_occurrences && $recurring->emitted_count >= $recurring->max_occurrences)
            || ($recurring->end_date && $next->startOfDay()->gt($recurring->end_date));

        $recurring->next_run_at = $exhausted ? null : $next;

        if ($exhausted) {
            $recurring->status = RecurringInvoiceStatuses::FINISHED;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(RecurringInvoice $recurring, Company $company, CarbonImmutable $today, CarbonImmutable $period): array
    {
        $recurring->loadMissing('items');

        $products = $recurring->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'code' => $this->renderItemText($item->code, $period, self::ITEM_CODE_MAX, 'código'),
            'description' => $this->renderItemText($item->description, $period, self::ITEM_DESCRIPTION_MAX, 'descripción'),
            'quantity' => $item->quantity,
            'price' => $item->price,
            'discount' => $item->discount,
            'iva' => $item->iva,
        ])->all();

        if ($company->isNegocioPopular()
            && collect($products)->contains(fn (array $product) => ! in_array($product['iva'], Company::NEGOCIO_POPULAR_IVA_CODES, true))) {
            throw new RuntimeException('Como RIMPE Negocio Popular solo puede facturar con IVA 0%: actualiza los productos de la factura recurrente.');
        }

        $data = [
            'customer_id' => $recurring->customer_id,
            'voucher_type_id' => VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id'),
            'point_id' => $recurring->emision_point_id,
            'date' => $today->toDateString(),
            'send' => $recurring->auto_send,
            'products' => $products,
            'aditionals' => array_map(fn (array $aditional) => [
                'name' => $aditional['name'] ?? '',
                'description' => $this->replacePlaceholders((string) ($aditional['description'] ?? ''), $period),
            ], $recurring->aditionals ?? []),
            'pay_method' => $recurring->pay_method,
            'description' => $recurring->description ? $this->replacePlaceholders($recurring->description, $period) : null,
            'payment_type' => $recurring->payment_type,
        ];

        if ($recurring->payment_type === PaymentTypes::CREDITO) {
            $data['due_date'] = $today->addDays($recurring->due_days ?? 0)->toDateString();
        }

        if ($recurring->payment_type === PaymentTypes::CONTADO && $this->gate->hasFinancieroAccess($company)) {
            [$data['source_type'], $data['source_id']] = $this->resolveSource($recurring);
        }

        return $data;
    }

    /**
     * Cuenta donde entra el cobro de contado. Una caja se resuelve a su
     * sesión abierta al momento de emitir.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveSource(RecurringInvoice $recurring): array
    {
        if ($recurring->source_type === MovementPartyRegistry::BANK_ACCOUNT && BankAccount::whereKey($recurring->source_id)->exists()) {
            return [MovementPartyRegistry::BANK_ACCOUNT, (int) $recurring->source_id];
        }

        if ($recurring->source_type === MovementPartyRegistry::CASH_REGISTER) {
            $cashRegister = CashRegister::with('activeSession')->find($recurring->source_id);

            if ($cashRegister && $cashRegister->activeSession) {
                return [MovementPartyRegistry::CASH_REGISTER_SESSION, $cashRegister->activeSession->id];
            }

            if ($cashRegister) {
                throw new RuntimeException("La caja \"{$cashRegister->name}\" está cerrada: ábrela o cambia la cuenta de cobro.");
            }
        }

        throw new RuntimeException('Falta la caja o cuenta bancaria donde entra el cobro de contado.');
    }

    /**
     * Código/descripción de la línea ya con el período; null = los del producto.
     */
    private function renderItemText(?string $template, CarbonImmutable $period, int $max, string $label): ?string
    {
        if (blank($template)) {
            return null;
        }

        $text = $this->replacePlaceholders($template, $period);

        if (mb_strlen($text) > $max) {
            throw new RuntimeException("El {$label} de la línea \"{$text}\" supera los {$max} caracteres que acepta el SRI.");
        }

        return $text;
    }

    public function replacePlaceholders(string $text, CarbonImmutable $period): string
    {
        $month = self::MONTH_NAMES[$period->month];

        return str_replace(self::PLACEHOLDERS, [
            $month,
            $period->format('m'),
            $period->format('Y'),
            "{$month} {$period->format('Y')}",
            $period->format('d/m/Y'),
        ], $text);
    }

    /**
     * Creador de la plantilla si sigue en la empresa; si no, el usuario
     * principal de la empresa.
     */
    private function resolveUser(RecurringInvoice $recurring): User
    {
        $companyId = Branch::whereKey($recurring->branch_id)->value('company_id');

        $belongs = CompanyUser::where('user_id', $recurring->user_id)->where('level_id', $companyId)->exists();
        $userId = $belongs
            ? $recurring->user_id
            : CompanyUser::where('level_id', $companyId)->orderBy('id')->value('user_id');

        return User::findOrFail((int) $userId);
    }
}
