<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Shop;
use App\Services\Shop\ShopLcXmlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopLifecycleController extends Controller
{
    public function process(Shop $shop, ShopLcXmlService $service): RedirectResponse
    {
        // Solo la Liquidación de Compra (voucher_type 3) tiene ciclo de vida electrónico.
        abort_unless((int) $shop->voucher_type === 3, 404);

        try {
            $service->process($shop);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Comprobante procesado con éxito.');
    }

    public function cancel(Shop $shop, ShopLcXmlService $service): RedirectResponse
    {
        abort_unless((int) $shop->voucher_type === 3, 404);

        try {
            $service->cancel($shop);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Comprobante anulado con éxito.');
    }

    public function download(Shop $shop): StreamedResponse
    {
        abort_unless($shop->xml && Storage::exists($shop->xml), 404, 'XML no disponible.');

        $filename = basename($shop->xml);

        return Storage::download($shop->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
