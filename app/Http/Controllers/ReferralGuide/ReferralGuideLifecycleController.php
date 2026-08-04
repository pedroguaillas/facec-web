<?php

namespace App\Http\Controllers\ReferralGuide;

use App\Http\Controllers\Controller;
use App\Models\ReferralGuide\ReferralGuide;
use App\Services\ReferralGuide\ReferralGuideLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReferralGuideLifecycleController extends Controller
{
    public function process(ReferralGuide $referralguide, ReferralGuideLifecycleService $service): JsonResponse
    {
        try {
            $service->process($referralguide);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Guía de remisión procesada con éxito.', 'referralguide' => $referralguide->fresh()]);
    }

    public function download(ReferralGuide $referralguide): StreamedResponse
    {
        abort_unless($referralguide->xml && Storage::exists($referralguide->xml), 404, 'XML no disponible.');

        $filename = basename($referralguide->xml);

        return Storage::download($referralguide->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
