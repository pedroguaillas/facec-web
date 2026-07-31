<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Shop\Shop;
use App\Services\Shop\Retention\RetentionXmlService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopStoreService
{
    private Company $company;

    private Branch $branch;

    public function __construct()
    {
        $this->company = Auth::user()->company;
        $this->branch = $this->company->branches()->orderBy('created_at')->first();
    }

    public function createShop(array $data): Shop
    {
        $shop = DB::transaction(function () use ($data) {
            $emisionPoint = EmisionPoint::findOrFail($data['point_id']);

            $inputs = $this->prepareShopData($data, $emisionPoint);
            $shop = $this->branch->shops()->create($inputs);

            $this->createShopItems($shop, $data['products'] ?? []);
            $this->createRetentionItems($shop, $data);
            $this->updateEmisionPointSequence($emisionPoint, $data);

            return $shop;
        });

        $this->sendVouchers($shop, $data);

        return $shop;
    }

    protected function prepareShopData(array $data, EmisionPoint $emisionPoint): array
    {
        $except = ['taxes', 'pay_methods', 'app_retention', 'send', 'point_id'];
        $inputs = collect($data)->except($except)->toArray();

        // Serie para liquidación en compra (voucher_type 3)
        if ((int) $data['voucher_type'] === 3) {
            $inputs['serie'] = $this->generateSerie($data['serie'], $emisionPoint->settlementonpurchase);
            $inputs['state'] = 'CREADO';
        }

        // Serie para retención
        $taxes = $data['taxes'] ?? [];
        if (! empty($data['app_retention']) && is_array($taxes) && count($taxes) > 0) {
            $inputs['serie_retencion'] = $this->generateSerie($data['serie_retencion'], $emisionPoint->retention);
            $inputs['state_retencion'] = 'CREADO';
        }

        return $inputs;
    }

    protected function generateSerie(string $serieBase, int $sequence): string
    {
        return substr($serieBase, 0, 8).str_pad($sequence, 9, '0', STR_PAD_LEFT);
    }

    protected function createShopItems(Shop $shop, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $items = array_map(fn ($p) => [
            'product_id' => $p['product_id'],
            'quantity' => $p['quantity'],
            'price' => $p['price'],
            'discount' => $p['discount'],
            'iva' => $p['iva'],
        ], $products);

        $shop->shopitems()->createMany($items);
    }

    protected function createRetentionItems(Shop $shop, array $data): void
    {
        $taxes = $data['taxes'] ?? [];

        if (empty($data['app_retention']) || empty($taxes)) {
            return;
        }

        $items = array_map(fn ($t) => [
            'code' => $t['code'],
            'tax_code' => $t['tax_code'],
            'base' => $t['base'],
            'porcentage' => $t['porcentage'],
            'value' => $t['value'],
        ], $taxes);

        $shop->shopretentionitems()->createMany($items);
    }

    protected function updateEmisionPointSequence(EmisionPoint $emisionPoint, array $data): void
    {
        if ((int) $data['voucher_type'] === 3) {
            $emisionPoint->settlementonpurchase += 1;
        }

        if (! empty($data['app_retention'])) {
            $emisionPoint->retention += 1;
        }

        $emisionPoint->save();
    }

    protected function sendVouchers(Shop $shop, array $data): void
    {
        $send = ! empty($data['send']);

        if ($send && (int) $shop->voucher_type === 3) {
            App::make(ShopLcXmlService::class)->process($shop);
        }

        if ($send && ! empty($data['app_retention']) && ! empty($data['taxes'])) {
            App::make(RetentionXmlService::class)->process($shop);
        }
    }
}
