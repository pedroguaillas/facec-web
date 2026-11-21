<?php

namespace App\Services\Order;

use App\Models\Branch;
use App\Models\Company;
use App\Models\MethodOfPayment;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderPayment;
use App\Models\Order\Repayment\Repayment;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class OrderPdfService
{
    public function __construct(protected Company $company) {}

    public function buildPdf(int $id): array
    {
        $movement = $this->getMovement($id);
        $beforeTaxChange = $this->isBeforeTaxChange($movement);
        $movement_items = $this->getMovementItems($id);
        $orderaditionals = OrderAditional::where('order_id', $id)->get();

        $company = $this->company;

        $branch = Branch::forSerie($this->company, $movement->serie);

        $enabledDiscount = $movement_items->contains(fn ($item) => $item->discount > 0);
        $enabledCodeAux = $movement_items->contains(fn ($item) => $item->aux_cod !== null);
        $voucherTypeSlug = $movement->voucherType->slug;
        $isElectronic = $movement->voucherType->requires_electronic;

        $pdf = $this->generatePdfView($movement, compact(
            'company', 'branch', 'movement', 'movement_items', 'voucherTypeSlug', 'isElectronic',
            'orderaditionals', 'beforeTaxChange', 'enabledDiscount', 'enabledCodeAux'
        ));

        return [$pdf, $movement];
    }

    public function buildPrintPdf(int $id)
    {
        $movement = $this->getMovement($id);
        $beforeTaxChange = $this->isBeforeTaxChange($movement);
        $movement_items = $this->getMovementItems($id);
        $company = $this->company;
        $branch = Branch::forSerie($company, $movement->serie);

        $pdf = app('dompdf.wrapper')->loadView(
            'vouchers.printf',
            compact('movement', 'company', 'branch', 'movement_items', 'beforeTaxChange')
        );

        $height = ($movement_items->count() > 3 ? 12 : 10) / 2.54 * 72;
        $pdf->setPaper([0, 0, (8 / 2.54) * 72, $height], 'portrait');

        return $pdf;
    }

    public function savePdf(int $id): void
    {
        [$pdf, $movement] = $this->buildPdf($id);
        $pdf->save(Storage::path(str_replace('.xml', '.pdf', $movement->xml)));
    }

    private function getMovement(int $id)
    {
        return Order::join('customers AS c', 'c.id', 'customer_id')
            ->select('orders.*', 'c.identication', 'c.name', 'c.address', 'c.email', 'c.phone')
            ->where('orders.id', $id)
            ->firstOrFail();
    }

    private function isBeforeTaxChange($movement): bool
    {
        return Carbon::parse(
            $movement->voucherType->slug === VoucherTypes::VENTA_NOTA_CREDITO ? $movement->date_order : $movement->date
        )->isBefore('2024-04-01');
    }

    private function getMovementItems(int $id)
    {
        return OrderItem::join('products', 'products.id', 'product_id')
            ->select('products.*', 'order_items.*')
            ->selectRaw('COALESCE(order_items.code, products.code) AS code, COALESCE(order_items.description, products.name) AS name')
            ->where('order_id', $id)
            ->get();
    }

    private function generatePdfView($movement, array $data)
    {
        return match ($movement->voucherType->slug) {
            VoucherTypes::VENTA_FACTURA => app('dompdf.wrapper')->loadView('vouchers.invoice', $this->withPayMethodData($movement, $data)),
            VoucherTypes::VENTA_NOTA_CREDITO => app('dompdf.wrapper')->loadView('vouchers.creditnote', $data),
            VoucherTypes::VENTA_NOTA_VENTA => app('dompdf.wrapper')->loadView('vouchers.notaventa', $this->withPayMethodData($movement, $data)),
            VoucherTypes::VENTA_PROFORMA => app('dompdf.wrapper')->loadView('vouchers.proforma', $data),
        };
    }

    private function withPayMethodData($movement, array $data): array
    {
        $descriptions = MethodOfPayment::pluck('description', 'code');
        $label = fn ($code): string => str_pad((string) $code, 2, '0', STR_PAD_LEFT).' - '.($descriptions[$code] ?? '');
        $data['payMethod'] = $label($movement->pay_method);
        // Una fila por forma de pago; ventas anteriores (sin order_payments) muestran una sola.
        $payments = OrderPayment::where('order_id', $movement->id)->orderBy('id')->get(['pay_method', 'payment_type', 'amount']);
        $data['payments'] = $payments->isEmpty()
            ? [['label' => $data['payMethod'], 'amount' => (float) $movement->total, 'is_credit' => $movement->payment_type === PaymentTypes::CREDITO]]
            : $payments->map(fn (OrderPayment $payment) => [
                'label' => $label($payment->pay_method),
                'amount' => $payment->amount,
                'is_credit' => $payment->payment_type === PaymentTypes::CREDITO,
            ])->all();
        // Columnas de plazo solo en factura, no en nota de venta (comparten esta partial).
        $data['isCredito'] = $movement->voucherType->slug === VoucherTypes::VENTA_FACTURA
            && $movement->payment_type === PaymentTypes::CREDITO;
        $data['dueDate'] = $movement->due_date?->format('d/m/Y');
        $data['repayments'] = Repayment::selectRaw('identification, sequential, date, SUM(base) base, SUM(iva) iva')
            ->join('repayment_taxes AS rt', 'repayments.id', 'repayment_id')
            ->where('order_id', $movement->id)
            ->groupBy('identification', 'sequential', 'date')
            ->get();

        return $data;
    }
}
