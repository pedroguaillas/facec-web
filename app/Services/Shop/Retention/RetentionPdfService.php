<?php

namespace App\Services\Shop\Retention;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shop\Shop;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Storage;

class RetentionPdfService
{
    public function __construct(protected Company $company) {}

    public function buildPdf(int $id): array
    {
        $movement = $this->getMovement($id);

        $retention_items = $movement->shopretentionitems;
        $company = $this->company;
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = $this->getBranch($movement->serie);
        $comprobante = $movement->voucher_type_name;
        // La retención es siempre el propio comprobante del contribuyente,
        // independiente del tipo de la compra sobre la que se retiene.
        $voucherTypeSlug = VoucherTypes::RETENCION;
        $isElectronic = true;

        $pdf = app('dompdf.wrapper')->loadView(
            'vouchers/retention',
            compact('movement', 'company', 'branch', 'retention_items', 'comprobante', 'voucherTypeSlug', 'isElectronic')
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
            ->join('voucher_types AS vt', 'vt.id', 'shops.voucher_type_id')
            ->select(
                'shops.id',
                'shops.date AS date_v',
                'vt.name AS voucher_type_name',
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
}
