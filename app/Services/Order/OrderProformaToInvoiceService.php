<?php

namespace App\Services\Order;

use App\Models\EmisionPoint;
use App\Models\Order\Order;
use App\Models\VoucherType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderProformaToInvoiceService
{
    public function __construct(private OrderStoreService $storeService) {}

    /**
     * Clona una proforma en una factura nueva, desde el mismo punto de
     * emisión de la proforma, y la envía al SRI de una vez (`send` siempre
     * `true` — es justo para eso que existe este endpoint). La factura queda
     * enlazada (`proforma_id`): mientras esa factura no esté anulada, la
     * proforma no se vuelve a convertir. La fila de la proforma se bloquea
     * para que un doble clic no genere dos facturas.
     *
     * @throws RuntimeException si la proforma ya tiene una factura vigente.
     */
    public function convert(Order $proforma): Order
    {
        return DB::transaction(function () use ($proforma) {
            $proforma = Order::whereKey($proforma->id)->lockForUpdate()->firstOrFail();
            $invoice = $proforma->convertedInvoiceSummary();

            if ($invoice) {
                throw new RuntimeException("Esta proforma ya fue convertida en la factura {$invoice['serie']}.");
            }

            return $this->createInvoice($proforma);
        });
    }

    private function createInvoice(Order $proforma): Order
    {
        $invoiceTypeId = VoucherType::where('slug', VoucherTypes::VENTA_FACTURA)->value('id');

        $orderData = [
            'customer_id' => $proforma->customer_id,
            'description' => $proforma->description,
            'discount' => $proforma->discount,
            'voucher_type_id' => $invoiceTypeId,
            'point_id' => $this->resolveEmisionPoint($proforma)->id,
            'date' => now()->format('Y-m-d'),
            'expiration_days' => $proforma->expiration_days,
            'pay_method' => $proforma->pay_method,
            'plate' => $proforma->plate,
            'guia' => $proforma->guia,
            'proforma_id' => $proforma->id,
            'send' => true,
            'products' => $proforma->orderitems->map(fn ($item) => [
                'product_id' => $item->product_id,
                'presentation_id' => $item->presentation_id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'ice' => $item->ice,
                'iva' => $item->iva,
            ])->all(),
            // OrderStoreService::createOrderAditionals ya agrega el aditional
            // requerido (RUC Proveedor); si lo clonamos también, queda duplicado.
            'aditionals' => $proforma->orderaditionals
                ->reject(fn ($aditional) => $aditional->name === Order::requiredAditional()['name'])
                ->map(fn ($aditional) => [
                    'name' => $aditional->name,
                    'description' => $aditional->description,
                ])->all(),
        ];

        return $this->storeService->createOrder($orderData);
    }

    /**
     * El punto de emisión no se guarda como FK en `Order` — se deriva del
     * establecimiento/punto ya codificados en la propia serie de la proforma
     * (`serie` = "estab-ptoEmi-secuencial").
     */
    private function resolveEmisionPoint(Order $proforma): EmisionPoint
    {
        $point = (int) explode('-', $proforma->serie)[1];

        return EmisionPoint::where('branch_id', $proforma->branch_id)
            ->where('point', $point)
            ->firstOrFail();
    }
}
