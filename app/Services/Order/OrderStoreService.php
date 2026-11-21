<?php

namespace App\Services\Order;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\IvaTax;
use App\Models\VoucherType;
use App\Services\AccountsReceivable\AccountsReceivableLedgerService;
use App\Services\Product\PresentationLineResolver;
use App\Services\Product\UnitSaleService;
use App\Services\Product\VoucherInventoryService;
use App\Services\SubscriptionGate;
use App\Services\Treasury\MovementService;
use App\Services\VoucherSequenceService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use App\Xml\AccessKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderStoreService
{
    /** Comprobantes que se cobran: llevan formas de pago y generan el ingreso en tesorería. */
    private const PAYMENT_SLUGS = [VoucherTypes::VENTA_FACTURA, VoucherTypes::VENTA_NOTA_VENTA];

    private Company $company;

    private Branch $branch;

    public function __construct(
        private OrderTotalsCalculator $totalsCalculator,
        private VoucherSequenceService $sequenceService,
    ) {
        $this->company = Auth::user()->company;
        $this->branch = $this->company->branches()->orderBy('created_at')->first();
    }

    public function createOrder(array $data): Order
    {
        $voucherType = VoucherType::findOrFail((int) $data['voucher_type_id']);

        if (in_array($voucherType->slug, DifferentiatedIvaService::VOUCHER_SLUGS, true)) {
            $originalProducts = $data['products'] ?? [];
            $data['products'] = app(DifferentiatedIvaService::class)
                ->applyToItems($this->company, $data['date'], $originalProducts);
            $data = OrderTotalsCalculator::discardSubmittedIfTaxesChanged($data, $originalProducts);
        }

        $orderSaved = DB::transaction(function () use ($data, $voucherType) {
            $emisionPoint = EmisionPoint::find($data['point_id']);

            $orderData = $this->prepareOrderData($data, $emisionPoint, $voucherType);
            $paymentLines = $this->resolvePaymentLines($data, $orderData, $voucherType);
            $order = $this->branch->orders()->create($orderData);

            $this->createPayments($order, $paymentLines);

            $this->createOrderItems($order, $data['products'], $voucherType);
            $this->createRepayments($order, $data['repayments'] ?? []);
            $this->createOrderAditionals($order, $data['aditionals'] ?? [], $voucherType);

            app(AccountsReceivableLedgerService::class)->registerFromOrder($order);

            return $order;
        });

        if (($data['send'] ?? false) && $voucherType->requires_electronic) {
            $this->sendToSRI($orderSaved);
        }

        return $orderSaved;
    }

    protected function prepareOrderData(array $data, EmisionPoint $emisionPoint, VoucherType $voucherType): array
    {
        $input = collect($data)->except(['products', 'send', 'aditionals', 'point_id', 'source_type', 'source_id', 'receipt_id', 'receipt_number', 'without_source', 'payments'])->toArray();

        if (array_key_exists('guia', $input) && trim($input['guia']) === '') {
            $input['guia'] = null;
        }

        $input['serie'] = $this->sequenceService->generateSerie($emisionPoint, $voucherType->slug);

        if ($voucherType->requires_electronic && $voucherType->code_sri !== null) {
            $input['authorization'] = AccessKey::generate($this->company, Carbon::parse($input['date']), $voucherType->code_sri, $input['serie']);
        }

        $input = array_merge($input, $this->totalsCalculator->resolve(
            $data['products'] ?? [],
            $input,
        ));

        if (! empty($data['payments'])) {
            $input['payment_type'] = $this->derivePaymentType($data['payments']);
            $input['pay_method'] = (int) $data['payments'][0]['pay_method'];
        }

        if (in_array($input['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true)) {
            app(SubscriptionGate::class)->ensureFinancieroAccess($this->company);
        }

        if (in_array($input['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true) && ! empty($input['due_date'])) {
            $orderDate = Carbon::parse($input['date']);
            $dueDate = Carbon::parse($input['due_date']);
            $input['expiration_days'] = $orderDate->diffInDays($dueDate);
            $input['due_date'] = $dueDate;
        }

        return $input;
    }

    /**
     * Una venta con varias formas de pago es a CREDITO si alguna línea lo es.
     *
     * @param  array<int, array{payment_type: string}>  $payments
     */
    protected function derivePaymentType(array $payments): string
    {
        return collect($payments)->contains(fn (array $line) => $line['payment_type'] === PaymentTypes::CREDITO)
            ? PaymentTypes::CREDITO
            : PaymentTypes::CONTADO;
    }

    /**
     * Líneas de pago de la venta. Sin `payments` (payload anterior) se arma
     * una sola línea por el total con `pay_method`/`payment_type` y la
     * caja/banco de nivel venta, así todo pasa por el mismo camino. Solo
     * factura y nota de venta cobran (proforma/nota de crédito no).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $orderData
     * @return array<int, array{pay_method: int, payment_type: string, amount: float, source_type: ?string, source_id: ?int, receipt_id: ?int, receipt_number: ?string}>
     */
    protected function resolvePaymentLines(array $data, array $orderData, VoucherType $voucherType): array
    {
        if (! in_array($voucherType->slug, self::PAYMENT_SLUGS, true)) {
            return [];
        }

        $lines = ! empty($data['payments']) ? $data['payments'] : [[
            'pay_method' => $orderData['pay_method'] ?? 20,
            'payment_type' => $orderData['payment_type'] ?? PaymentTypes::CONTADO,
            'amount' => $orderData['total'],
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'receipt_id' => $data['receipt_id'] ?? null,
            'receipt_number' => $data['receipt_number'] ?? null,
        ]];

        $this->ensurePaymentsMatchTotal($lines, (float) $orderData['total']);

        return array_map(fn (array $line) => [
            'pay_method' => (int) $line['pay_method'],
            'payment_type' => $line['payment_type'] ?? PaymentTypes::CONTADO,
            'amount' => round((float) $line['amount'], 2),
            'source_type' => $line['source_type'] ?? null,
            'source_id' => isset($line['source_id']) ? (int) $line['source_id'] : null,
            'receipt_id' => $line['receipt_id'] ?? null,
            'receipt_number' => $line['receipt_number'] ?? null,
        ], array_values($lines));
    }

    /**
     * El backend puede recalcular el total (IVA diferenciado en feriado) y
     * dejarlo distinto del que se validó en el request: los <pago> del XML
     * deben sumar el total guardado o el SRI rechaza la factura. Se corta
     * antes de crear cobros; la transacción revierte la venta.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws RuntimeException
     */
    protected function ensurePaymentsMatchTotal(array $lines, float $total): void
    {
        $total = round($total, 2);
        $paymentsTotal = round(array_sum(array_map(fn (array $line) => (float) $line['amount'], $lines)), 2);

        if (abs($paymentsTotal - $total) > 0.01) {
            throw new RuntimeException("El total de la venta se recalculó a {$total} (IVA diferenciado): ajuste las formas de pago a ese total.");
        }
    }

    /**
     * Crea las formas de pago y, por cada línea de contado con caja/banco
     * (solo con plan financiero), su ingreso en tesorería. `paid` = lo cobrado
     * de contado; lo que queda a crédito lo carga la cartera.
     *
     * @param  array<int, array{pay_method: int, payment_type: string, amount: float, source_type: ?string, source_id: ?int, receipt_id: ?int, receipt_number: ?string}>  $lines
     */
    protected function createPayments(Order $order, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $collectsInTreasury = app(SubscriptionGate::class)->hasFinancieroAccess($this->company);
        $firstMovementId = null;
        $paidInCash = 0.0;

        foreach ($lines as $line) {
            $movementId = null;

            if ($line['payment_type'] === PaymentTypes::CONTADO) {
                $paidInCash += $line['amount'];

                if ($collectsInTreasury && $line['source_type'] && $line['source_id']) {
                    $movementId = app(MovementService::class)->create([
                        'branch_id' => $this->branch->id,
                        'date' => $order->date,
                        'type' => MovementTypes::INCOME,
                        'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
                        'source_type' => $line['source_type'],
                        'source_id' => $line['source_id'],
                        'receipt_id' => $line['receipt_id'],
                        'receipt_number' => $line['receipt_number'],
                        'origin_type' => MovementPartyRegistry::ORDER,
                        'origin_id' => $order->id,
                        'amount' => $line['amount'],
                        'description' => "Cobro venta {$order->serie}",
                        'user_id' => Auth::id(),
                    ])->id;

                    $firstMovementId ??= $movementId;
                }
            }

            $order->payments()->create([
                'pay_method' => $line['pay_method'],
                'payment_type' => $line['payment_type'],
                'amount' => $line['amount'],
                'movement_id' => $movementId,
            ]);
        }

        // ANTICIPO: `paid` lo fija la aplicación del anticipo en la cartera.
        $order->update([
            'movement_id' => $firstMovementId,
            ...($order->payment_type === PaymentTypes::ANTICIPO ? [] : ['paid' => round($paidInCash, 2)]),
        ]);
    }

    protected function createOrderItems(Order $order, array $products, VoucherType $voucherType): void
    {
        if (empty($products)) {
            return;
        }

        $presentations = app(PresentationLineResolver::class)->resolve($products);
        $unitSales = app(UnitSaleService::class);
        // La nota de crédito no lleva características (docs/caracteristicas-producto.md §7).
        $snapshots = $voucherType->slug === VoucherTypes::VENTA_NOTA_CREDITO ? [] : $unitSales->characteristicsByProduct(array_column($products, 'product_id'));

        $orderItems = array_map(fn ($product, $presentation) => [
            'product_id' => $product['product_id'],
            'presentation_id' => $presentation['presentation_id'],
            'conversion_factor' => $presentation['conversion_factor'],
            // Solo la factura recurrente manda code/description; si no, los de la presentación.
            'code' => $product['code'] ?? $presentation['code'],
            'description' => $product['description'] ?? $presentation['description'],
            'details' => $snapshots[$product['product_id']] ?? null,
            'quantity' => $product['quantity'],
            'price' => $product['price'],
            'discount' => $product['discount'],
            'ice' => $product['ice'] ?? 0,
            'iva' => $product['iva'],
        ], $products, $presentations);

        $order->orderitems()->createMany($orderItems);

        app(VoucherInventoryService::class)->syncOrder($order, isNew: true);
        $unitSales->syncStockWithoutInventory(array_column($products, 'product_id'), (bool) $this->company->inventory);
    }

    protected function createRepayments(Order $order, array $repayments): void
    {
        if (empty($repayments)) {
            return;
        }

        $validRepayments = array_filter($repayments, fn ($repayment) => ! empty($repayment['identification']) &&
            ! empty($repayment['sequential']) &&
            ! empty($repayment['date']) &&
            ! empty($repayment['authorization'])
        );

        $ivaTaxes = IvaTax::all(['code', 'percentage'])->pluck('percentage', 'code');

        foreach ($validRepayments as $repayment) {
            $contec = substr($repayment['identification'], 2, 1);
            $type_prov = ($contec == 6 || $contec == 9) ? 2 : 1;

            $newRepayment = $order->repayments()->create([
                'type_id_prov' => 4,
                'identification' => $repayment['identification'],
                'cod_country' => 593,
                'type_prov' => $type_prov,
                'type_document' => 1,
                'sequential' => $repayment['sequential'],
                'date' => $repayment['date'],
                'authorization' => $repayment['authorization'],
            ]);

            $repaymentTaxes = [];

            foreach ($repayment['repaymentTaxes'] as $tax) {
                $repaymentTaxes[] = [
                    'iva_tax_code' => $tax['iva_tax_code'],
                    'percentage' => $ivaTaxes[$tax['iva_tax_code']],
                    'base' => $tax['base'],
                    'iva' => $tax['iva'],
                ];
            }
            $newRepayment->repaymenttaxes()->createMany($repaymentTaxes);
        }
    }

    protected function createOrderAditionals(Order $order, array $aditionals, VoucherType $voucherType): void
    {
        // "RUC Proveedor" es un requisito del XML del SRI (repayments/reembolso);
        // no aplica a nota de venta/proforma, que nunca se envían al SRI.
        if ($voucherType->requires_electronic) {
            $aditionals[] = Order::requiredAditional();
        }

        $validAditionals = array_filter($aditionals, fn ($aditional) => ! empty($aditional['name']) && ! empty($aditional['description'])
        );

        $formattedAditionals = array_map(fn ($aditional) => [
            'name' => $aditional['name'],
            'description' => $aditional['description'],
        ], $validAditionals);

        if (! empty($formattedAditionals)) {
            $order->orderaditionals()->createMany($formattedAditionals);
        }
    }

    protected function sendToSRI($order): void
    {
        // Recargar el order porque los atributos por defecto no cargan directamente.
        $order->refresh();
        ProcessVoucherJob::dispatch('order', $order->id, $this->company->id)->afterCommit();
    }
}
