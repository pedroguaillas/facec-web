<?php

namespace App\Http\Controllers\RecurringInvoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecurringInvoice\RecurringInvoiceRequest;
use App\Http\Resources\RecurringInvoiceResources;
use App\Models\Order\RecurringInvoice;
use App\Services\RecurringInvoice\RecurringInvoiceService;
use App\StaticClasses\RecurringRunStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class RecurringInvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');

        $recurringInvoices = RecurringInvoice::with(['customer', 'items'])
            ->when($search, function ($query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

                $query->where(function ($q) use ($escaped) {
                    $q->where('name', 'LIKE', "%{$escaped}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'LIKE', "%{$escaped}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate((int) $request->input('paginate', 15))
            ->withQueryString();

        return RecurringInvoiceResources::collection($recurringInvoices);
    }

    public function store(RecurringInvoiceRequest $request, RecurringInvoiceService $service): JsonResponse
    {
        $recurringInvoice = $service->save($request->validated());

        return response()->json(new RecurringInvoiceResources($recurringInvoice->load(['customer', 'items.product'])), 201);
    }

    /**
     * Plantilla + sus últimas emisiones.
     */
    public function edit(RecurringInvoice $recurringInvoice): JsonResponse
    {
        return response()->json([
            ...(new RecurringInvoiceResources($recurringInvoice->load(['customer', 'items.product'])))->resolve(),
            'runs' => $this->runs($recurringInvoice, 10),
        ]);
    }

    public function update(RecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice, RecurringInvoiceService $service): JsonResponse
    {
        $recurringInvoice = $service->save($request->validated(), $recurringInvoice);

        return response()->json(new RecurringInvoiceResources($recurringInvoice->load(['customer', 'items.product'])));
    }

    /**
     * Solo si nunca emitió: con facturas emitidas se pausa (el historial se conserva).
     */
    public function destroy(RecurringInvoice $recurringInvoice): JsonResponse
    {
        if ($recurringInvoice->runs()->where('status', RecurringRunStatuses::ISSUED)->exists()) {
            return response()->json(['succes' => false, 'message' => 'Ya emitió facturas: pausala en lugar de eliminarla.'], 422);
        }

        $recurringInvoice->delete();

        return response()->json(['succes' => true]);
    }

    public function pause(RecurringInvoice $recurringInvoice, RecurringInvoiceService $service): JsonResponse
    {
        return $this->transition(fn () => $service->pause($recurringInvoice));
    }

    public function resume(RecurringInvoice $recurringInvoice, RecurringInvoiceService $service): JsonResponse
    {
        return $this->transition(fn () => $service->resume($recurringInvoice));
    }

    /**
     * "Emitir ahora": el período vencido (reintento de un ERROR) o el
     * próximo por adelantado.
     */
    public function emit(RecurringInvoice $recurringInvoice, RecurringInvoiceService $service): JsonResponse
    {
        try {
            $order = $service->emit($recurringInvoice->id, Auth::user());
        } catch (RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'succes' => true,
            'order' => ['id' => $order->id, 'serie' => $order->serie],
            'recurring_invoice' => new RecurringInvoiceResources($recurringInvoice->fresh(['customer', 'items.product'])),
        ], 201);
    }

    public function history(Request $request, RecurringInvoice $recurringInvoice): JsonResponse
    {
        return response()->json($this->runs($recurringInvoice, (int) $request->input('paginate', 15)));
    }

    /**
     * @param  callable(): RecurringInvoice  $action
     */
    private function transition(callable $action): JsonResponse
    {
        try {
            $recurringInvoice = $action();
        } catch (RuntimeException $e) {
            return response()->json(['succes' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(new RecurringInvoiceResources($recurringInvoice->load(['customer', 'items.product'])));
    }

    /**
     * @return array<int, array{id: int, period_date: string, status: string, message: string|null, attempts: int, order: array{id: int, serie: string|null, state: string|null}|null, updated_at: string|null}>
     */
    private function runs(RecurringInvoice $recurringInvoice, int $limit): array
    {
        return $recurringInvoice->runs()
            ->with('order:id,serie,state')
            ->latest('period_date')
            ->take($limit)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'period_date' => $run->period_date->format('Y-m-d'),
                'status' => $run->status,
                'message' => $run->message,
                'attempts' => $run->attempts,
                'order' => $run->order ? ['id' => $run->order->id, 'serie' => $run->order->serie, 'state' => $run->order->state] : null,
                'updated_at' => $run->updated_at?->format('Y-m-d H:i'),
            ])
            ->all();
    }
}
