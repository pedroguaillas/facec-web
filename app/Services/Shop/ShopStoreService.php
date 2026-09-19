<?php

namespace App\Services\Shop;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Shop\Shop;
use App\Models\VoucherType;
use App\Services\Shop\Retention\RetentionTotalsCalculator;
use App\Services\VoucherSequenceService;
use App\StaticClasses\VoucherTypes;
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

            return $shop;
        });

        $this->sendVouchers($shop, $data);

        return $shop;
    }

    protected function prepareShopData(array $data, EmisionPoint $emisionPoint): array
    {
        $except = ['taxes', 'pay_methods', 'app_retention', 'send', 'point_id'];
        $inputs = collect($data)->except($except)->toArray();

        $voucherType = VoucherType::findOrFail($data['voucher_type_id']);

        // Serie para liquidación en compra: único tipo de Compras con secuencial propio.
        if ($voucherType->requires_sequence) {
            $inputs['serie'] = app(VoucherSequenceService::class)
                ->generateSerie($emisionPoint, $voucherType->slug);
            $inputs['state'] = 'CREADO';
        }

        // Serie para retención
        $taxes = $data['taxes'] ?? [];
        if (! empty($data['app_retention']) && is_array($taxes) && count($taxes) > 0) {
            $inputs['serie_retencion'] = app(VoucherSequenceService::class)
                ->generateSerie($emisionPoint, VoucherTypes::RETENCION);
            $inputs['state_retencion'] = 'CREADO';
        }

        return $inputs;
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

        $items = (new RetentionTotalsCalculator)->recalculate($items, $shop->id);

        $shop->shopretentionitems()->createMany($items);
    }

    protected function sendVouchers(Shop $shop, array $data): void
    {
        $send = ! empty($data['send']);

        if ($send && $shop->voucherType->slug === VoucherTypes::COMPRA_LIQUIDACION_COMPRA) {
            ProcessVoucherJob::dispatch('shop', $shop->id, $this->company->id)->afterCommit();
        }

        if ($send && ! empty($data['app_retention']) && ! empty($data['taxes'])) {
            ProcessVoucherJob::dispatch('shop_retention', $shop->id, $this->company->id)->afterCommit();
        }
    }
}
