<?php

namespace App\Services\ReferralGuide;

use App\Models\ReferralGuide\ReferralGuide;
use App\StaticClasses\VoucherStates;
use Illuminate\Support\Facades\DB;

class ReferralGuideUpdateService
{
    public function __construct(private ReferralGuide $referralguide) {}

    public function updateReferralGuide(array $data): void
    {
        $locked = [
            VoucherStates::SENDED,
            VoucherStates::RECEIVED,
            VoucherStates::IN_PROCESS,
            VoucherStates::AUTHORIZED,
            VoucherStates::CANCELED,
        ];

        if (in_array($this->referralguide->state, $locked)) {
            return;
        }

        DB::transaction(function () use ($data) {
            $inputs = collect($data)->except(['products', 'send'])->toArray();
            $this->referralguide->update($inputs);

            $this->referralguide->referralguidetems()->delete();

            $products = $data['products'] ?? [];

            if (! empty($products)) {
                $items = array_map(fn ($p) => [
                    'product_id' => $p['product_id'],
                    'quantity' => $p['quantity'],
                ], $products);

                $this->referralguide->referralguidetems()->createMany($items);
            }
        });
    }
}
