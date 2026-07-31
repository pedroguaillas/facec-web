<?php

namespace App\Services\Shop;

use App\Models\Shop\Shop;
use App\Models\Shop\ShopRetentionItem;

class ShopUpdateService
{
    const EXCLUDED_FIELDS = ['id', 'taxes', 'pay_methods', 'app_retention', 'send'];

    public function __construct(protected Shop $shop) {}

    public function updateShop(array $data): Shop
    {
        if ($this->shop->update(collect($data)->except(self::EXCLUDED_FIELDS)->toArray())) {
            $this->syncRetentionItems($data);
        }

        return $this->shop->fresh();
    }

    protected function syncRetentionItems(array $data): void
    {
        $taxes = $data['taxes'] ?? [];

        if ($this->shop->voucher_type >= 4 || empty($data['app_retention']) || empty($taxes)) {
            return;
        }

        $items = array_map(fn ($t) => [
            'code' => $t['code'],
            'tax_code' => $t['tax_code'],
            'base' => $t['base'],
            'porcentage' => $t['porcentage'],
            'value' => $t['value'],
        ], $taxes);

        ShopRetentionItem::where('shop_id', $this->shop->id)->delete();
        $this->shop->shopretentionitems()->createMany($items);
    }
}
