<?php

namespace App\Services\ReferralGuide;

use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\ReferralGuide\ReferralGuide;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReferralGuideStoreService
{
    private Company $company;

    private Branch $branch;

    public function __construct()
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

            $emisionPoint->referralguide += 1;
            $emisionPoint->save();

            return $referralguide;
        });

        if (! empty($data['send'])) {
            app(ReferralGuideLifecycleService::class)->process($referralguide);
        }

        return $referralguide;
    }

    protected function prepareData(array $data, EmisionPoint $emisionPoint): array
    {
        $except = ['products', 'send', 'point_id'];
        $inputs = collect($data)->except($except)->toArray();

        $inputs['serie'] = substr($data['serie'], 0, 8).str_pad($emisionPoint->referralguide, 9, '0', STR_PAD_LEFT);

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
