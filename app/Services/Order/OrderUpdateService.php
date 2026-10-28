<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\Services\Product\PresentationLineResolver;
use App\Services\Product\UnitSaleService;
use App\Services\Product\VoucherInventoryService;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderUpdateService
{
    public function __construct(
        private OrderTotalsCalculator $totalsCalculator,
        private DifferentiatedIvaService $differentiatedIvaService,
    ) {}

    public function updateOrder(Order $order, array $data): Order
    {
        if (in_array($order->voucherType->slug, DifferentiatedIvaService::VOUCHER_SLUGS, true)) {
            $data['products'] = $this->differentiatedIvaService
                ->applyToItems(Auth::user()->company, $order->date, $data['products'] ?? []);
        }

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

            $previousProductIds = $order->orderitems()->pluck('product_id')->all();

            $this->updateOrderItems($order, $data['products'] ?? []);
            $this->updateOrderAditionals($order, $data['aditionals'] ?? []);

            app(VoucherInventoryService::class)->syncOrder($order->fresh());
            app(UnitSaleService::class)->syncStockWithoutInventory(
                [...$previousProductIds, ...array_column($data['products'] ?? [], 'product_id')],
                (bool) Auth::user()->company->inventory,
            );
        });

        return $order->fresh();
    }

    /**
     * Las líneas se recrean. Con presentación, código/descripción salen de
     * ella; sin presentación, se conservan por producto los propios de la
     * factura recurrente, porque el formulario de edición no los manda.
     */
    protected function updateOrderItems(Order $order, array $products): void
    {
        $overrides = OrderItem::where('order_id', $order->id)
            ->whereNull('presentation_id')
            ->where(fn ($query) => $query->whereNotNull('code')->orWhereNotNull('description'))
            ->get(['product_id', 'code', 'description'])
            ->keyBy('product_id');

        OrderItem::where('order_id', $order->id)->delete();

        if (empty($products)) {
            return;
        }

        $presentations = app(PresentationLineResolver::class)->resolve($products);
        // La nota de crédito no lleva características (docs/caracteristicas-producto.md §7).
        $snapshots = $order->voucherType()->value('slug') === VoucherTypes::VENTA_NOTA_CREDITO
            ? []
            : app(UnitSaleService::class)->characteristicsByProduct(array_column($products, 'product_id'));

        $items = array_map(fn ($product, $presentation) => [
            'product_id' => $product['product_id'],
            'presentation_id' => $presentation['presentation_id'],
            'conversion_factor' => $presentation['conversion_factor'],
            'code' => $presentation['presentation_id'] ? $presentation['code'] : ($overrides[$product['product_id']]->code ?? null),
            'description' => $presentation['presentation_id'] ? $presentation['description'] : ($overrides[$product['product_id']]->description ?? null),
            'details' => $snapshots[$product['product_id']] ?? null,
            'quantity' => $product['quantity'],
            'price' => $product['price'],
            'discount' => $product['discount'],
            'ice' => $product['ice'] ?? 0,
            'iva' => $product['iva'],
        ], $products, $presentations);

        $order->orderitems()->createMany($items);
    }

    protected function updateOrderAditionals(Order $order, array $aditionals): void
    {
        OrderAditional::where('order_id', $order->id)->delete();

        // "RUC Proveedor" es un requisito del XML del SRI; no aplica a
        // nota de venta/proforma, que nunca se envían al SRI.
        if ($order->voucherType->requires_electronic) {
            $aditionals[] = Order::requiredAditional();
        }

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
