<?php

namespace App\Services\Shop\Retention;

use App\Models\Company;
use App\Models\Shop\Shop;
use App\Services\SriSoapService;
use App\Services\VoucherLifecycleService;
use App\StaticClasses\VoucherStates;
use App\Xml\RetentionBuilder;
use Illuminate\Support\Facades\Auth;

class RetentionXmlService
{
    public function __construct(
        protected VoucherLifecycleService $lifecycle,
        protected SriSoapService $sriSoapService
    ) {}

    public function process(Shop $shop)
    {
        $company = Auth::user()->company;

        if (! $company->active_voucher) {
            return;
        }

        $state = $shop->state_retencion;

        if (! $state || in_array($state, [VoucherStates::SAVED, VoucherStates::RETURNED, VoucherStates::REJECTED])) {
            if ($shop->state_retencion === VoucherStates::RETURNED && $shop->extra_detail_retention === 'CLAVE ACCESO REGISTRADA.') {
                $this->sri->authorize($id);

                return;
            }

            if ($shop->serie_retencion && $shop->date_retention) {
                $this->lifecycle->saveAndSign(
                    company: $company,
                    model: $shop,
                    xml: $this->buildXml($company, $shop->id),
                    dateField: 'date_retention',
                    xmlField: 'xml_retention',
                    stateField: 'state_retencion',
                    extraDetailField: 'extra_detail_retention',
                    onSigned: fn () => $this->send($shop),
                );
            }
        } elseif ($state === VoucherStates::SIGNED) {
            $this->send($shop);
        } elseif (in_array($state, [VoucherStates::SENDED, VoucherStates::RECEIVED, VoucherStates::IN_PROCESS])) {
            $this->authorize($shop);
        }
    }

    private function buildXml(Company $company, $shopId)
    {
        $shop = Shop::join('providers AS p', 'p.id', 'shops.provider_id')
            ->select('p.*', 'shops.*')
            ->where('shops.id', $shopId)
            ->first();

        return (new RetentionBuilder($company, $shop))->build();
    }

    private function send(Shop $shop)
    {
        $this->sriSoapService->send(
            model: $shop,
            onReceived: fn () => $this->authorize($shop),
            xmlField: 'xml_retention',
            stateField: 'state_retencion',
            extraDetailField: 'extra_detail_retention',
        );
    }

    private function authorize(Shop $shop)
    {
        $this->sriSoapService->authorize(
            model: $shop,
            xmlField: 'xml_retention',
            stateField: 'state_retencion',
            authorizedField: 'autorized_retention',
            extraDetailField: 'extra_detail_retention',
            authorizationField: 'authorization_retention',
            onAuthorized: fn () => null
        );
    }
}
