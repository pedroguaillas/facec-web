<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Shop;
use App\Services\Shop\ShopLcXmlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopLifecycleController extends Controller
{
    public function process(Shop $shop, ShopLcXmlService $service): JsonResponse
    {
        // Solo la Liquidación de Compra (voucher_type 3) tiene ciclo de vida electrónico.
        abort_unless((int) $shop->voucher_type === 3, 404);

        try {
            $service->process($shop);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Comprobante procesado con éxito.', 'shop' => $shop->fresh()]);
    }

    public function cancel(Shop $shop, ShopLcXmlService $service): JsonResponse
    {
        abort_unless((int) $shop->voucher_type === 3, 404);

        try {
            $service->cancel($shop);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
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
