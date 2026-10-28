<?php

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Http\Requests\Treasury\MovementReceiptUpdateRequest;
use App\Http\Requests\Treasury\PaymentReceiptStoreRequest;
use App\Http\Resources\MovementResources;
use App\Models\Treasury\Movement;
use App\Models\Treasury\PaymentReceipt;
use App\Services\Treasury\MovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReceiptController extends Controller
{
    /**
     * Paso 1: sube el comprobante. El pago lo vincula después con `receipt_id`.
     */
    public function store(PaymentReceiptStoreRequest $request): JsonResponse
    {
        $file = $request->file('receipt');

        $receipt = PaymentReceipt::create([
            'branch_id' => Auth::user()->company->branches()->orderBy('created_at')->value('id'),
            'path' => $file->store('treasury/receipts'),
            'mime_type' => $file->getMimeType(),
            'user_id' => Auth::id(),
        ]);

        return response()->json(['id' => $receipt->id], 201);
    }

    /**
     * Archivo del comprobante de un movimiento (pagos y movimientos manuales).
     */
    public function show(Movement $movement): StreamedResponse|JsonResponse
    {
        if (! $movement->receipt_path || ! Storage::exists($movement->receipt_path)) {
            return response()->json(['succes' => false, 'message' => 'El movimiento no tiene comprobante.'], 404);
        }

        return Storage::response($movement->receipt_path);
    }

    /**
     * Corrige el nro. y/o la imagen del comprobante de un movimiento ya
     * registrado (ej. se guardó sin comprobante).
     */
    public function update(MovementReceiptUpdateRequest $request, Movement $movement, MovementService $service): JsonResponse
    {
        if (! $movement->involvesBankAccount()) {
            return response()->json(['succes' => false, 'message' => 'Solo los movimientos con cuenta bancaria llevan comprobante.'], 422);
        }

        $movement = $service->updateReceipt($movement, $request->validated());

        return response()->json(new MovementResources($movement->load(['source', 'destination', 'origin'])));
    }
}
