<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class ShopLcPdfService
{
    public function __construct(protected Company $company) {}

    public function stream(int $id)
    {
        return $this->buildPdf($id)->stream();
    }

    private function buildPdf(int $id)
    {
        $movement = $this->getMovement($id);
        $beforeTaxChange = Carbon::parse($movement->date)->isBefore(Carbon::parse('2024-04-01'));

        $movement_items = ShopItem::join('products', 'products.id', 'product_id')
            ->select('products.*', 'shop_items.*')
            ->where('shop_id', $id)
            ->get();

        $company = $this->company;
        $company->logo_dir = $company->logo_dir ?: 'default.png';

        $branch = $this->getBranch($movement->serie);

        return Pdf::loadView(
            'vouchers/settlementonpurchase',
            compact('movement', 'branch', 'company', 'movement_items', 'beforeTaxChange')
        );
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
