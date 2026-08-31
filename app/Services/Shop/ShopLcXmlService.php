<?php

namespace App\Services\Shop;

use App\Mail\ShopLcShipped;
use App\Models\Company;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Models\Shop\ShopItem;
use App\Services\SriSoapService;
use App\Services\VoucherLifecycleService;
use App\StaticClasses\VoucherStates;
use App\Xml\SettlementOnPurchaseBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ShopLcXmlService
{
    public function __construct(
        private VoucherLifecycleService $lifecycle,
        private SriSoapService $sriSoapService
    ) {}

    public function process(Shop $shop, Company $company)
    {
        if (! $company->active_voucher) {
            return;
        }

        $state = $shop->state;

        if (! $state || in_array($state, [VoucherStates::SAVED, VoucherStates::RETURNED, VoucherStates::REJECTED])) {

            if ($state === VoucherStates::RETURNED && $shop->extra_detail === 'CLAVE ACCESO REGISTRADA.') {
                $this->authorize($shop);

                return;
            }

            $this->lifecycle->saveAndSign(
                company: $company,
                model: $shop,
                xml: $this->buildXml($company, $shop->id),
                onSigned: fn () => $this->send($shop)
            );
        } elseif ($state === VoucherStates::SIGNED) {
            $this->send($shop);
        } elseif (in_array($state, [VoucherStates::SENDED, VoucherStates::RECEIVED, VoucherStates::IN_PROCESS])) {
            $this->authorize($shop);
        }
    }

    public function cancel(Shop $shop): mixed
    {
        return $this->sriSoapService->cancel($shop);
    }

    private function buildXml(Company $company, int $id)
    {
        $shop = Shop::join('providers AS p', 'p.id', 'shops.provider_id')
            ->select('p.identication', 'p.name', 'p.address', 'p.type_identification', 'shops.*')
            ->where('shops.id', $id)
            ->first();

        $shopItems = ShopItem::select('p.id', 'p.code', 'p.name', 'shop_items.quantity', 'shop_items.price', 'shop_items.iva')
            ->join('products AS p', 'p.id', 'product_id')
            ->where('shop_id', $id)
            ->get();

        return (new SettlementOnPurchaseBuilder($company, $shop, $shopItems))->build();
    }

    private function send(Shop $shop)
    {
        $this->sriSoapService->send(
            model: $shop,
            onReceived: fn () => $this->authorize($shop)
        );
    }

    private function authorize(Shop $shop)
    {
        $this->sriSoapService->authorize(
            model: $shop,
            onAuthorized: fn () => $this->sendShopMail($shop),
            inProcessAttemptsField: 'in_process_attempts',
        );
    }

    /**
     * Envía copia de la liquidación en compra autorizada por correo al proveedor
     * si tiene email registrado. Un fallo de correo se loggea pero nunca revierte
     * ni reporta como error la autorización del SRI, que ya quedó guardada antes
     * de este callback (mismo patrón que OrderSriService::sendOrderMail).
     */
    private function sendShopMail(Shop $shop): void
    {
        $email = Provider::find($shop->provider_id)?->email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new ShopLcShipped($shop));
            $shop->update(['send_mail_set_purchase' => true]);
        } catch (Throwable $e) {
            Log::error('ShopLcShipped mail failed', [
                'shop_id' => $shop->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
