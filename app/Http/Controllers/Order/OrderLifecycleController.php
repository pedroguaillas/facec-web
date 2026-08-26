<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Services\Order\OrderLifecycleService;
use App\Services\Order\OrderSriService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderLifecycleController extends Controller
{
    public function process(Order $order, OrderLifecycleService $service): JsonResponse
    {
        try {
            $service->process($order);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Comprobante procesado con éxito.', 'order' => $order->fresh()]);
    }

    public function cancel(Order $order, OrderLifecycleService $service): JsonResponse
    {
        try {
            $result = $service->cancel($order);
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
                'order' => $order->fresh(),
            ], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Comprobante anulado con éxito.', 'order' => $order->fresh()]);
    }

    public function mail(Order $order, OrderSriService $service): JsonResponse
    {
        try {
            $service->resendMail($order);
        } catch (\Throwable $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['succes' => true, 'message' => 'Correo enviado con éxito.', 'order' => $order->fresh()]);
    }

    public function download(Order $order): StreamedResponse
    {
        abort_unless($order->xml && Storage::exists($order->xml), 404, 'XML no disponible.');

        $filename = basename($order->xml);

        return Storage::download($order->xml, $filename, ['Content-Type' => 'application/xml']);
    }
}
