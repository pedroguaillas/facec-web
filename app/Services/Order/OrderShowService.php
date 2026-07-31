<?php

namespace App\Services\Order;

use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;

class OrderShowService
{
    public function getOrderDetail(Order $order): array
    {
        return [
            'order' => $this->getFilteredOrder($order),
            'order_items' => $this->getOrderItems($order->id),
            'order_aditionals' => $this->getOrderAditionals($order->id),
            'products' => $this->getProducts($order->id),
            'customers' => $this->getCustomers($order->customer_id),
        ];
    }

    protected function getFilteredOrder(Order $order): array
    {
        return collect($order->toArray())
            ->filter(fn ($value) => $value !== null)
            ->all();
    }

    protected function getOrderItems(int $orderId)
    {
        return OrderItem::join('products', 'product_id', 'products.id')
            ->join('iva_taxes AS it', 'it.code', 'order_items.iva')
            ->selectRaw('order_items.id,quantity,price,discount,order_items.ice,products.name,products.ice AS codice,product_id,it.code AS iva,it.percentage')
            ->where('order_id', $orderId)
            ->get()
            ->map(function ($item) {
                $item->total_iva = round(($item->quantity * $item->price) - $item->discount, 2);

                if ($item->codice !== null) {
                    unset($item->ice);
                    unset($item->codice);
                }

                return $item;
            });
    }

    protected function getOrderAditionals(int $orderId)
    {
        return OrderAditional::select('id', 'name', 'description')
            ->where('order_id', $orderId)
            ->get();
    }

    protected function getProducts(int $orderId)
    {
        return Product::join('order_items AS oi', 'product_id', 'products.id')
            ->select('products.*')
            ->where('order_id', $orderId)
            ->get();
    }

    protected function getCustomers(?int $customerId)
    {
        return Customer::where('id', $customerId)->get();
    }
}
