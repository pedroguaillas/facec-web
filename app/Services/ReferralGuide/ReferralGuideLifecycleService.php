<?php

namespace App\Services\ReferralGuide;

use App\Models\ReferralGuide\ReferralGuide;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Services\SriSoapService;
use App\Services\VoucherLifecycleService;
use App\Xml\ReferralGuideBuilder;
use Illuminate\Support\Facades\Auth;

class ReferralGuideLifecycleService
{
    public function __construct(
        private VoucherLifecycleService $lifecycle,
        private SriSoapService $sriSoapService
    ) {}

    public function process(ReferralGuide $referralguide)
    {
        $company = Auth::user()->company;

        if (! $company->active_voucher) {
            return;
        }

        $state = $referralguide->state;

        if (in_array($state, [VoucherStates::SAVED, VoucherStates::RETURNED, VoucherStates::REJECTED])) {

            if ($state === VoucherStates::RETURNED && $referralguide->extra_detail === 'CLAVE ACCESO REGISTRADA.') {
                $this->sri->authorize($referralguide);

                return;
            }

            $this->lifecycle->saveAndSign(
                company: $company,
                model: $referralguide,
                xml: $this->buildXml($referralguide->id, $company),
                onSigned: fn () => $this->send($referralguide)
            );
        } elseif ($state === VoucherStates::SIGNED) {
            $this->send($referralguide);
        } elseif (in_array($state, [VoucherStates::SENDED, VoucherStates::RECEIVED, VoucherStates::IN_PROCESS])) {
            $this->authorize($referralguide);
        }
    }

    private function buildXml($id, $company)
    {
        $referralguide = ReferralGuide::join('customers AS c', 'c.id', 'customer_id')
            ->join('carriers AS ca', 'ca.id', 'carrier_id')
            ->select('ca.identication AS ca_identication', 'ca.name AS ca_name', 'ca.license_plate', 'c.identication', 'c.name', 'referral_guides.*')
            ->where('referral_guides.id', $id)
            ->first();

        $referralguideitems = ReferralGuideItem::join('products AS p', 'p.id', 'product_id')
            ->where('referral_guide_id', $id)
            ->get();

        return (new ReferralGuideBuilder($company, $referralguide, $referralguideitems))->build();
    }

    private function send(ReferralGuide $referralGuide)
    {
        $this->sriSoapService->send(
            model: $referralGuide,
            onReceived: fn () => $this->authorize($referralGuide)
        );
    }

    private function authorize(ReferralGuide $referralGuide)
    {
        $this->sriSoapService->authorize(
            model: $referralGuide,
            onAuthorized: fn () => null
        );
    }
}
