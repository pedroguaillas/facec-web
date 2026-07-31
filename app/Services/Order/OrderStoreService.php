<?php

namespace App\Services\Order;

use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\Product\IvaTax;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderStoreService
{
    private Company $company;

    private Branch $branch;

    public function __construct(
        private OrderLifecycleService $xmlService,
    ) {
        $this->company = Auth::user()->company;
        $this->branch = $this->company->branches()->orderBy('created_at')->first();
    }

    public function createOrder(array $data): Order
    {
        $orderSaved = DB::transaction(function () use ($data) {
            $emisionPoint = EmisionPoint::find($data['point_id']);

            $orderData = $this->prepareOrderData($data, $emisionPoint);
            $order = $this->branch->orders()->create($orderData);

            $this->createOrderItems($order, $data['products']);
            $this->updateEmisionPointSequence($emisionPoint, $data['voucher_type']);
            $this->createRepayments($order, $data['repayments'] ?? []);
            $this->createOrderAditionals($order, $data['aditionals'] ?? []);

            return $order;
        });

        if ($data['send'] ?? false) {
            $this->sendToSRI($orderSaved);
        }

        return $orderSaved;
    }

    protected function prepareOrderData(array $data, EmisionPoint $emisionPoint): array
    {
        $input = collect($data)->except(['products', 'send', 'aditionals', 'point_id'])->toArray();

        if (array_key_exists('guia', $input) && trim($input['guia']) === '') {
            $input['guia'] = null;
        }

        $input['serie'] = $this->generateSerie($data['serie'], $emisionPoint, $data['voucher_type']);

        return $input;
    }

    protected function generateSerie(string $serieBase, EmisionPoint $emisionPoint, int $voucherType): string
    {
        $serie = substr($serieBase, 0, 8);
        $field = $voucherType == 1 ? 'invoice' : 'creditnote';
        $sequence = str_pad($emisionPoint->{$field}, 9, '0', STR_PAD_LEFT);

        return "{$serie}{$sequence}";
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

    protected function updateEmisionPointSequence(EmisionPoint $emisionPoint, int $voucherType): void
    {
        $field = $voucherType == 1 ? 'invoice' : 'creditnote';
        $emisionPoint->{$field}++;
        $emisionPoint->save();
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

    protected function createOrderAditionals(Order $order, array $aditionals): void
    {
        if (empty($aditionals)) {
            return;
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
        $this->xmlService->process($order);
    }
}
