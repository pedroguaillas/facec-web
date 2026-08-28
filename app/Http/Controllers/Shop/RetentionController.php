<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVoucherJob;
use App\Models\Shop\Shop;
use App\Services\Shop\Retention\RetentionPdfService;
use App\Services\Shop\Retention\RetentionXmlService;
use App\StaticClasses\VoucherStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RetentionController extends Controller
{
    public function pdf(int $id, RetentionPdfService $service)
    {
        return $service->stream($id);
    }

    public function process(Shop $shop): JsonResponse
    {
        abort_unless($shop->serie_retencion !== null, 404);

        $company = Auth::user()->company;

        if (! $company?->active_voucher) {
            return response()->json(['succes' => false, 'message' => 'La facturación electrónica no está activa para esta empresa.'], 422);
        }

        if (in_array($shop->state_retencion, VoucherStates::FINAL_STATES, true)) {
            return response()->json(['succes' => false, 'message' => 'La retención ya fue procesada.', 'shop' => $shop], 422);
        }

        ProcessVoucherJob::dispatch('shop_retention', $shop->id, $company->id)->afterCommit();

        return response()->json(['succes' => true, 'message' => 'Retención en proceso.', 'shop' => $shop->fresh()]);
    }

    public function cancel(Shop $shop, RetentionXmlService $service): JsonResponse
    {
        abort_unless($shop->serie_retencion !== null, 404);

        try {
            $result = $service->cancel($shop);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $result['canceled']) {
            return response()->json([
                'succes' => false,
                'message' => match ($result['status']) {
                    'PENDIENTE DE ANULAR' => 'La anulación fue solicitada y está pendiente de confirmación por el SRI.',
                    null => 'No se pudo confirmar el estado de la anulación con el SRI. Intenta nuevamente en unos minutos.',
                    default => "El comprobante no está anulado en el SRI (estado actual: {$result['status']}).",
                },
                'shop' => $shop->fresh(),
            ], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Retención anulada con éxito.', 'shop' => $shop->fresh()]);
    }

    public function download(Shop $shop): StreamedResponse
    {
        abort_unless($shop->xml_retention && Storage::exists($shop->xml_retention), 404, 'XML no disponible.');

        $filename = basename($shop->xml_retention);

        return Storage::download($shop->xml_retention, $filename, ['Content-Type' => 'application/xml']);
    }
}
