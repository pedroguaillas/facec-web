<?php

namespace App\Console\Commands;

use App\Jobs\EmitRecurringInvoiceJob;
use App\Models\Order\RecurringInvoice;
use App\StaticClasses\RecurringInvoiceStatuses;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:recurring {--dry-run : Solo listar, no encolar}')]
#[Description('Encola la emisión de las facturas recurrentes activas cuya próxima fecha y hora ya llegó (las que fallaron se reintentan cada hora). Programado a diario en routes/console.php.')]
class EmitRecurringInvoices extends Command
{
    const RETRY_AFTER_MINUTES = 60;

    public function handle(): int
    {
        // Sin usuario autenticado: BranchScope no aplica, se recorren todas las empresas.
        $ids = RecurringInvoice::withoutGlobalScope('branch')
            ->where('status', RecurringInvoiceStatuses::ACTIVE)
            ->where('next_run_at', '<=', now())
            // Una que falló se reintenta cada hora, no en cada minuto.
            ->where(fn ($query) => $query->whereNull('last_error')
                ->orWhere('last_run_at', '<=', now()->subMinutes(self::RETRY_AFTER_MINUTES)))
            ->orderBy('id')
            ->pluck('id');

        if (! $this->option('dry-run')) {
            $ids->each(fn (int $id) => EmitRecurringInvoiceJob::dispatch($id));
        }

        $this->info(($this->option('dry-run') ? 'Por emitir: ' : 'Encoladas: ').$ids->count());

        return self::SUCCESS;
    }
}
