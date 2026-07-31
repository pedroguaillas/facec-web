<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Services\Order\OrderLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderLifecycleController extends Controller
{
    public function process(Order $order, OrderLifecycleService $service): RedirectResponse
    {
        try {
            $service->process($order);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Comprobante procesado con éxito.');
    }

    public function cancel(Order $order, OrderLifecycleService $service): RedirectResponse
    {
        try {
            $service->cancel($order);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Comprobante anulado con éxito.');
    }

    public function download(Order $order): StreamedResponse
    {
        abort_unless($order->xml && Storage::exists($order->xml), 404, 'XML no disponible.');

        $filename = basename($order->xml);

        return Storage::download($order->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
