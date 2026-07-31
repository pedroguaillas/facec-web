<?php

namespace App\Services\Shop\Retention;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shop\Shop;
use Illuminate\Support\Facades\Storage;

class RetentionPdfService
{
    public function __construct(protected Company $company) {}

    public function buildPdf(int $id): array
    {
        $movement = $this->getMovement($id);
        $movement->voucher_type = 7;

        $retention_items = $movement->shopretentionitems;
        $company = $this->company;
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = $this->getBranch($movement->serie);
        $comprobante = $this->voucherTypeLabel($movement->voucher_type_v);

        $pdf = app('dompdf.wrapper')->loadView(
            'vouchers/retention',
            compact('movement', 'company', 'branch', 'retention_items', 'comprobante')
        );

        return [$pdf, $movement];
    }

    public function stream(int $id)
    {
        [$pdf] = $this->buildPdf($id);

        return $pdf->stream();
    }

    public function savePdf(int $id): void
    {
        [$pdf, $movement] = $this->buildPdf($id);
        $pdf->save(Storage::path(str_replace('.xml', '.pdf', $movement->xml)));
    }

    private function getMovement(int $id)
    {
        return Shop::join('providers AS p', 'provider_id', 'p.id')
            ->select(
                'shops.id',
                'shops.date AS date_v',
                'shops.voucher_type AS voucher_type_v',
                'shops.date_retention AS date',
                'shops.serie AS serie_retencion',
                'shops.serie_retencion AS serie',
                'shops.autorized_retention AS autorized',
                'shops.xml_retention AS xml',
                'shops.authorization_retention AS authorization',
                'p.name',
                'p.identication',
                'p.email',
            )
            ->where('shops.id', $id)
            ->firstOrFail();
    }

    private function getBranch(string $serie): Branch
    {
        $store = (int) substr($serie, 0, 3);

        return Branch::where(['company_id' => $this->company->id, 'store' => $store])->first()
            ?? Branch::where('company_id', $this->company->id)->orderBy('created_at')->first();
    }

    private function voucherTypeLabel(int $tv): string
    {
        return match ($tv) {
            1 => 'Factura',
            2 => 'Nota Venta',
            3 => 'Liquidación en Compra',
            5 => 'Nota de Débito',
            default => '',
        };
    }
}
