<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\DB;

class OrderUpdateService
{
    public function __construct(
        private OrderTotalsCalculator $totalsCalculator,
    ) {}

    public function updateOrder(Order $order, array $data): Order
    {
        DB::transaction(function () use ($order, $data) {
            $input = collect($data)->except(['id', 'products', 'send', 'aditionals', 'serie'])->toArray();

            $input = array_merge($input, $this->totalsCalculator->calculate(
                $data['products'] ?? [],
                (float) ($input['discount'] ?? 0),
                $input,
                $order->id,
            ));

            $order->update([
                ...$input,
                'state' => VoucherStates::SAVED,
            ]);

            $this->updateOrderItems($order, $data['products'] ?? []);
            $this->updateOrderAditionals($order, $data['aditionals'] ?? []);
        });

        return $order->fresh();
    }

    protected function updateOrderItems(Order $order, array $products): void
    {
        OrderItem::where('order_id', $order->id)->delete();

        if (empty($products)) {
            return;
        }

        $items = array_map(fn ($product) => [
            'product_id' => $product['product_id'],
            'quantity' => $product['quantity'],
            'price' => $product['price'],
            'discount' => $product['discount'],
            'ice' => $product['ice'] ?? 0,
            'iva' => $product['iva'],
        ], $products);

        $order->orderitems()->createMany($items);
    }

    protected function updateOrderAditionals(Order $order, array $aditionals): void
    {
        OrderAditional::where('order_id', $order->id)->delete();

        $aditionals[] = Order::REQUIRED_ADITIONAL;

        $valid = array_filter($aditionals, fn ($a) => ! empty($a['name']) && ! empty($a['description'])
        );

        if (! empty($valid)) {
            $order->orderaditionals()->createMany(
                array_map(fn ($a) => [
                    'name' => $a['name'],
                    'description' => $a['description'],
                ], $valid)
            );
        }
    }
}
