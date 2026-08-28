<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVoucherJob;
use App\Models\Shop\Shop;
use App\Services\Shop\ShopLcXmlService;
use App\StaticClasses\VoucherStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopLifecycleController extends Controller
{
    public function process(Shop $shop): JsonResponse
    {
        // Solo la Liquidación de Compra (voucher_type 3) tiene ciclo de vida electrónico.
        abort_unless((int) $shop->voucher_type === 3, 404);

        $company = Auth::user()->company;

        if (! $company?->active_voucher) {
            return response()->json(['succes' => false, 'message' => 'La facturación electrónica no está activa para esta empresa.'], 422);
        }

        if (in_array($shop->state, VoucherStates::FINAL_STATES, true)) {
            return response()->json(['succes' => false, 'message' => 'El comprobante ya fue procesado.', 'shop' => $shop], 422);
        }

        ProcessVoucherJob::dispatch('shop', $shop->id, $company->id)->afterCommit();

        return response()->json(['succes' => true, 'message' => 'Comprobante en proceso.', 'shop' => $shop->fresh()]);
    }

    public function cancel(Shop $shop, ShopLcXmlService $service): JsonResponse
    {
        abort_unless((int) $shop->voucher_type === 3, 404);

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

        return response()->json(['succes' => true, 'message' => 'Comprobante anulado con éxito.', 'shop' => $shop->fresh()]);
    }

    public function download(Shop $shop): StreamedResponse
    {
        abort_unless($shop->xml && Storage::exists($shop->xml), 404, 'XML no disponible.');

        $filename = basename($shop->xml);

        return Storage::download($shop->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
