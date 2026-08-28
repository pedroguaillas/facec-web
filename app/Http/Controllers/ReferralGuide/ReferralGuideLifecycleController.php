<?php

namespace App\Http\Controllers\ReferralGuide;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVoucherJob;
use App\Models\ReferralGuide\ReferralGuide;
use App\StaticClasses\VoucherStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReferralGuideLifecycleController extends Controller
{
    public function process(ReferralGuide $referralguide): JsonResponse
    {
        $company = Auth::user()->company;

        if (! $company?->active_voucher) {
            return response()->json(['succes' => false, 'message' => 'La facturación electrónica no está activa para esta empresa.'], 422);
        }

        if (in_array($referralguide->state, VoucherStates::FINAL_STATES, true)) {
            return response()->json(['succes' => false, 'message' => 'La guía de remisión ya fue procesada.', 'referralguide' => $referralguide], 422);
        }

        ProcessVoucherJob::dispatch('referral_guide', $referralguide->id, $company->id)->afterCommit();

        return response()->json(['succes' => true, 'message' => 'Guía de remisión en proceso.', 'referralguide' => $referralguide->fresh()]);
    }

    public function download(ReferralGuide $referralguide): StreamedResponse
    {
        abort_unless($referralguide->xml && Storage::exists($referralguide->xml), 404, 'XML no disponible.');

        $filename = basename($referralguide->xml);

        return Storage::download($referralguide->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
