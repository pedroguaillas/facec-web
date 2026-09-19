<?php

namespace App\Services\Order;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\IvaTax;
use App\Models\VoucherType;
use App\Services\VoucherSequenceService;
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

        $orderSaved = DB::transaction(function () use ($data, $voucherType) {
            $emisionPoint = EmisionPoint::find($data['point_id']);

            $orderData = $this->prepareOrderData($data, $emisionPoint, $voucherType);
            $order = $this->branch->orders()->create($orderData);

            $this->createOrderItems($order, $data['products']);
            $this->createRepayments($order, $data['repayments'] ?? []);
            $this->createOrderAditionals($order, $data['aditionals'] ?? [], $voucherType);

            return $order;
        });

        if (($data['send'] ?? false) && $voucherType->requires_electronic) {
            $this->sendToSRI($orderSaved);
        }

        return $orderSaved;
    }

    protected function prepareOrderData(array $data, EmisionPoint $emisionPoint, VoucherType $voucherType): array
    {
        $input = collect($data)->except(['products', 'send', 'aditionals', 'point_id'])->toArray();

        if (array_key_exists('guia', $input) && trim($input['guia']) === '') {
            $input['guia'] = null;
        }

        $input['serie'] = $this->sequenceService->generateSerie($emisionPoint, $voucherType->slug);

        $input = array_merge($input, $this->totalsCalculator->calculate(
            $data['products'] ?? [],
            (float) ($input['discount'] ?? 0),
            $input,
        ));

        return $input;
    }

    protected function createOrderItems(Order $order, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $orderItems = [];
        $inventoryItems = [];

        foreach ($products as $product) {
            $orderItems[] = [
                'product_id' => $product['product_id'],
                'quantity' => $product['quantity'],
                'price' => $product['price'],
                'discount' => $product['discount'],
                'ice' => $product['ice'] ?? 0,
                'iva' => $product['iva'],
            ];

            if ($this->company->inventory) {
                $inventoryItems[] = [
                    'product_id' => $product['product_id'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'],
                    'type' => 'Venta',
                    'date' => Carbon::today()->format('Y-m-d'),
                ];
            }
        }

        $order->orderitems()->createMany($orderItems);

        if ($this->company->inventory && ! empty($inventoryItems)) {
            $order->inventories()->createMany($inventoryItems);
        }
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
