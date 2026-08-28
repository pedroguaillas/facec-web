<?php

namespace App\StaticClasses;

use App\Models\Order\Order;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use App\Services\Order\OrderLifecycleService;
use App\Services\ReferralGuide\ReferralGuideLifecycleService;
use App\Services\Shop\Retention\RetentionXmlService;
use App\Services\Shop\ShopLcXmlService;

class VoucherJobRegistry
{
    /**
     * @var array<string, array{model: class-string, service: class-string, state: string}>
     */
    const TYPES = [
        'order' => ['model' => Order::class, 'service' => OrderLifecycleService::class, 'state' => 'state'],
        'shop' => ['model' => Shop::class, 'service' => ShopLcXmlService::class, 'state' => 'state'],
        'referral_guide' => ['model' => ReferralGuide::class, 'service' => ReferralGuideLifecycleService::class, 'state' => 'state'],
        'shop_retention' => ['model' => Shop::class, 'service' => RetentionXmlService::class, 'state' => 'state_retencion'],
    ];
}
