<?php

namespace App\Jobs;

use App\Services\RecurringInvoice\RecurringInvoiceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite el período vencido de una factura recurrente. Un fallo queda
 * registrado en `recurring_invoice_runs` (ERROR) y el comando
 * `invoices:recurring` lo reintenta a la hora — por eso el job no reintenta solo.
 */
class EmitRecurringInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $recurringInvoiceId) {}

    public function uniqueId(): string
    {
        return "recurring-invoice:{$this->recurringInvoiceId}";
    }

    public function handle(RecurringInvoiceService $service): void
    {
        try {
            $service->emit($this->recurringInvoiceId);
        } catch (Throwable $e) {
            Log::warning('EmitRecurringInvoiceJob: no se pudo emitir', [
                'recurring_invoice_id' => $this->recurringInvoiceId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
