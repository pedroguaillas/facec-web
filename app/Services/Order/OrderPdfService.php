<?php

namespace App\Services\Order;

use App\Models\Branch;
use App\Models\Company;
use App\Models\MethodOfPayment;
use App\Models\Order\Order;
use App\Models\Order\OrderAditional;
use App\Models\Order\OrderItem;
use App\Models\Order\Repayment\Repayment;
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
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = $this->getBranch($movement->serie);

        $enabledDiscount = $movement_items->contains(fn ($item) => $item->discount > 0);
        $enabledCodeAux = $movement_items->contains(fn ($item) => $item->aux_cod !== null);

        $pdf = $this->generatePdfView($movement, compact(
            'company', 'branch', 'movement', 'movement_items',
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

        $pdf = app('dompdf.wrapper')->loadView(
            'vouchers.printf',
            compact('movement', 'company', 'movement_items', 'beforeTaxChange')
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
            ->select('orders.*', 'c.identication', 'c.name', 'c.address', 'c.email')
            ->where('orders.id', $id)
            ->firstOrFail();
    }

    private function isBeforeTaxChange($movement): bool
    {
        return Carbon::parse(
            $movement->voucher_type == 4 ? $movement->date_order : $movement->date
        )->isBefore('2024-04-01');
    }

    private function getMovementItems(int $id)
    {
        return OrderItem::join('products', 'products.id', 'product_id')
            ->select('products.*', 'order_items.*')
            ->where('order_id', $id)
            ->get();
    }

    private function getBranch(string $serie): Branch
    {
        $store = (int) substr($serie, 0, 3);

        return Branch::where(['company_id' => $this->company->id, 'store' => $store])->first()
            ?? Branch::where('company_id', $this->company->id)->orderBy('created_at')->first();
    }

    private function generatePdfView($movement, array $data)
    {
        if ($movement->voucher_type == 1) {
            $data['payMethod'] = MethodOfPayment::where('code', $movement->pay_method)->value('description');
            $data['repayments'] = Repayment::selectRaw('identification, sequential, date, SUM(base) base, SUM(iva) iva')
                ->join('repayment_taxes AS rt', 'repayments.id', 'repayment_id')
                ->where('order_id', $movement->id)
                ->groupBy('identification', 'sequential', 'date')
                ->get();

            return app('dompdf.wrapper')->loadView('vouchers.invoice', $data);
        }

        return app('dompdf.wrapper')->loadView('vouchers.creditnote', $data);
    }
}
