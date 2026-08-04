<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Shop;
use App\Services\Shop\Retention\RetentionPdfService;
use App\Services\Shop\Retention\RetentionXmlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RetentionController extends Controller
{
    public function pdf(int $id, RetentionPdfService $service)
    {
        return $service->stream($id);
    }

    public function process(Shop $shop, RetentionXmlService $service): JsonResponse
    {
        abort_unless($shop->serie_retencion !== null, 404);

        try {
            $service->process($shop);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Retención procesada con éxito.', 'shop' => $shop->fresh()]);
    }

    public function cancel(Shop $shop, RetentionXmlService $service): JsonResponse
    {
        abort_unless($shop->serie_retencion !== null, 404);

        try {
            $service->cancel($shop);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
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
