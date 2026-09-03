<?php

namespace App\Services\Shop\Retention;

use App\Mail\RetentionShipped;
use App\Models\Company;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Services\SriSoapService;
use App\Services\VoucherLifecycleService;
use App\StaticClasses\VoucherStates;
use App\Xml\RetentionBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RetentionXmlService
{
    public function __construct(
        protected VoucherLifecycleService $lifecycle,
        protected SriSoapService $sriSoapService
    ) {}

    public function process(Shop $shop, Company $company)
    {
        if (! $company->active_voucher) {
            return;
        }

        $state = $shop->state_retencion;

        if (! $state || in_array($state, [VoucherStates::SAVED, VoucherStates::RETURNED, VoucherStates::REJECTED])) {
            if ($shop->state_retencion === VoucherStates::RETURNED && $shop->extra_detail_retention === 'CLAVE ACCESO REGISTRADA.') {
                $this->authorize($shop);

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

    public function cancel(Shop $shop): mixed
    {
        return $this->sriSoapService->cancel($shop, 'xml_retention', 'state_retencion');
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
            onAuthorized: fn () => $this->sendRetentionMail($shop),
            inProcessAttemptsField: 'in_process_attempts_retention',
        );
    }

    /**
     * Envía copia de la retención autorizada por correo al proveedor si tiene
     * email registrado. Un fallo de correo se loggea pero nunca revierte ni
     * reporta como error la autorización del SRI, que ya quedó guardada antes
     * de este callback (mismo patrón que OrderSriService::sendOrderMail).
     */
    private function sendRetentionMail(Shop $shop): void
    {
        if (! Provider::find($shop->provider_id)?->email) {
            return;
        }

        try {
            $this->resendMail($shop);
        } catch (Throwable $e) {
            Log::error('RetentionShipped mail failed', [
                'shop_id' => $shop->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envía (o reenvía) el correo de la retención. Usado tanto automáticamente
     * al autorizar como manualmente desde el botón "reenviar" del frontend.
     * Propaga cualquier error para que el llamador decida cómo manejarlo.
     */
    public function resendMail(Shop $shop): void
    {
        if ($shop->state_retencion !== VoucherStates::AUTHORIZED) {
            throw new \RuntimeException('La retención debe estar autorizada para poder enviar el correo.');
        }

        $email = Provider::find($shop->provider_id)?->email;

        if (! $email) {
            throw new \RuntimeException('El proveedor no tiene correo electrónico registrado.');
        }

        Mail::to($email)->send(new RetentionShipped($shop));

        $shop->update(['send_mail_retention' => true]);
    }
}
