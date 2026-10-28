<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Services\Product\UnitSaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ShopLcPdfService
{
    public function __construct(protected Company $company) {}

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

    private function buildPdf(int $id): array
    {
        $movement = $this->getMovement($id);
        $beforeTaxChange = Carbon::parse($movement->date)->isBefore(Carbon::parse('2024-04-01'));

        $movement_items = ShopItem::join('products', 'products.id', 'shop_items.product_id')
            ->leftJoin('product_presentations AS pp', 'pp.id', 'shop_items.presentation_id')
            ->select('products.*', 'shop_items.*')
            ->selectRaw('COALESCE(pp.code, products.code) AS code')
            ->selectRaw("CASE WHEN pp.id IS NULL THEN products.name ELSE CONCAT(products.name, ' (', pp.name, ')') END AS name")
            ->where('shop_id', $id)
            ->get();

        $characteristics = app(UnitSaleService::class)->characteristicsByProduct($movement_items->pluck('product_id'));
        $movement_items->each(fn (ShopItem $item) => $item->setAttribute('details', $characteristics[$item->product_id] ?? null));

        $company = $this->company;
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = $this->getBranch($movement->serie);
        $voucherTypeSlug = $movement->voucherType->slug;
        $isElectronic = $movement->voucherType->requires_electronic;

        $pdf = Pdf::loadView(
            'vouchers/settlementonpurchase',
            compact('movement', 'branch', 'company', 'movement_items', 'beforeTaxChange', 'voucherTypeSlug', 'isElectronic')
        );

        return [$pdf, $movement];
    }

    private function getMovement(int $id)
    {
        return Shop::join('providers AS p', 'provider_id', 'p.id')
            ->select('shops.*', 'p.identication', 'p.name', 'p.address')
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
