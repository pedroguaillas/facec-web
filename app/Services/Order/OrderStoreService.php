<?php

namespace App\Services\Order;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\IvaTax;
use App\Models\Treasury\Movement;
use App\Models\VoucherType;
use App\Services\AccountsReceivable\AccountsReceivableLedgerService;
use App\Services\Product\PresentationLineResolver;
use App\Services\Product\VoucherInventoryService;
use App\Services\SubscriptionGate;
use App\Services\Treasury\MovementService;
use App\Services\VoucherSequenceService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderStoreService
{
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
        $voucherType = VoucherType::findOrFail($data['voucher_type_id']);

        if (in_array($voucherType->slug, DifferentiatedIvaService::VOUCHER_SLUGS, true)) {
            $data['products'] = app(DifferentiatedIvaService::class)
                ->applyToItems($this->company, $data['date'], $data['products'] ?? []);
        }

        $orderSaved = DB::transaction(function () use ($data, $voucherType) {
            $emisionPoint = EmisionPoint::find($data['point_id']);

            $orderData = $this->prepareOrderData($data, $emisionPoint, $voucherType);
            $order = $this->branch->orders()->create($orderData);

            if ($order->movement_id) {
                Movement::whereKey($order->movement_id)
                    ->update(['origin_type' => MovementPartyRegistry::ORDER, 'origin_id' => $order->id]);
            }

            $this->createOrderItems($order, $data['products']);
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
        $input = collect($data)->except(['products', 'send', 'aditionals', 'point_id', 'source_type', 'source_id', 'without_source'])->toArray();

        if (array_key_exists('guia', $input) && trim($input['guia']) === '') {
            $input['guia'] = null;
        }

        $input['serie'] = $this->sequenceService->generateSerie($emisionPoint, $voucherType->slug);

        $input = array_merge($input, $this->totalsCalculator->calculate(
            $data['products'] ?? [],
            (float) ($input['discount'] ?? 0),
            $input,
        ));

        if (in_array($input['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true)) {
            app(SubscriptionGate::class)->ensureFinancieroAccess($this->company);
        }

        if (in_array($input['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true) && ! empty($input['due_date'])) {
            $orderDate = Carbon::parse($input['date']);
            $dueDate = Carbon::parse($input['due_date']);
            $input['expiration_days'] = $orderDate->diffInDays($dueDate);
            $input['due_date'] = $dueDate;
        }

        if (($input['payment_type'] ?? null) === PaymentTypes::CONTADO && ! empty($data['source_type']) && ! empty($data['source_id'])
            && app(SubscriptionGate::class)->hasFinancieroAccess($this->company)) {
            $movement = app(MovementService::class)->create([
                'branch_id' => $this->branch->id,
                'date' => $input['date'],
                'type' => MovementTypes::INCOME,
                'transaction_type' => MovementTransactionTypes::SALE_COLLECTION,
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'amount' => $input['total'],
                'description' => "Cobro venta {$input['serie']}",
                'user_id' => Auth::id(),
            ]);

            $input['movement_id'] = $movement->id;
        }

        return $input;
    }

    protected function createOrderItems(Order $order, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $presentations = app(PresentationLineResolver::class)->resolve($products);

        $orderItems = array_map(fn ($product, $presentation) => [
            'product_id' => $product['product_id'],
            'presentation_id' => $presentation['presentation_id'],
            'conversion_factor' => $presentation['conversion_factor'],
            // Solo la factura recurrente manda code/description; si no, los de la presentación.
            'code' => $product['code'] ?? $presentation['code'],
            'description' => $product['description'] ?? $presentation['description'],
            'quantity' => $product['quantity'],
            'price' => $product['price'],
            'discount' => $product['discount'],
            'ice' => $product['ice'] ?? 0,
            'iva' => $product['iva'],
        ], $products, $presentations);

        $order->orderitems()->createMany($orderItems);

        app(VoucherInventoryService::class)->syncOrder($order, isNew: true);
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
