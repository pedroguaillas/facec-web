<?php

namespace App\Services\ReferralGuide;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\ReferralGuide\ReferralGuide;
use App\Services\VoucherSequenceService;
use App\StaticClasses\VoucherTypes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReferralGuideStoreService
{
    private Company $company;

    private Branch $branch;

    public function __construct(private VoucherSequenceService $sequenceService)
    {
        $this->company = Auth::user()->company;
        $this->branch = $this->company->branches()->orderBy('created_at')->first();
    }

    public function createReferralGuide(array $data): ReferralGuide
    {
        $referralguide = DB::transaction(function () use ($data) {
            $emisionPoint = EmisionPoint::findOrFail($data['point_id']);

            $inputs = $this->prepareData($data, $emisionPoint);
            $referralguide = $this->branch->referralguides()->create($inputs);

            $this->createItems($referralguide, $data['products'] ?? []);

            return $referralguide;
        });

        if (! empty($data['send'])) {
            ProcessVoucherJob::dispatch('referral_guide', $referralguide->id, $this->company->id)->afterCommit();
        }

        return $referralguide;
    }

    protected function prepareData(array $data, EmisionPoint $emisionPoint): array
    {
        $except = ['products', 'send', 'point_id'];
        $inputs = collect($data)->except($except)->toArray();

        $inputs['serie'] = $this->sequenceService->generateSerie($emisionPoint, VoucherTypes::GUIA_REMISION);
        // Fecha de emisión: siempre la fecha del servidor al crear, no un
        // campo que mande el cliente (igual criterio que `serie`).
        $inputs['date'] = now()->format('Y-m-d');
        // El transporte también inicia el día de emisión.
        $inputs['date_start'] = $inputs['date'];

        return $inputs;
    }

    protected function createItems(ReferralGuide $referralguide, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $items = array_map(fn ($p) => [
            'product_id' => $p['product_id'],
            'quantity' => $p['quantity'],
        ], $products);

        $referralguide->referralguidetems()->createMany($items);
    }
}
