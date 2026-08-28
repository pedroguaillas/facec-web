<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\StaticClasses\VoucherJobRegistry;
use App\StaticClasses\VoucherStates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('vouchers:reprocess-stuck {--type=* : order|shop|referral_guide|shop_retention (por defecto: order)} {--since= : Solo creados desde esta fecha (Y-m-d, por defecto: hoy)} {--all : Ignora el filtro de fecha, incluye comprobantes de cualquier día} {--dry-run : Solo listar, no encolar}')]
#[Description('Reencola ProcessVoucherJob para los comprobantes que quedaron en un estado no final (CREADO, DEVUELTA, etc.) — para reprocesar en bloque en vez de uno por uno desde la interfaz. Por defecto solo toca los creados hoy.')]
class ReprocessStuckVouchers extends Command
{
    public function handle(): int
    {
        $types = $this->option('type') ?: ['order'];
        $dryRun = (bool) $this->option('dry-run');
        $since = $this->option('all') ? null : ($this->option('since') ?: now()->toDateString());

        if ($since) {
            $this->comment("Filtrando comprobantes creados desde {$since} (usá --all para incluir todos).");
        }

        foreach ($types as $type) {
            $config = VoucherJobRegistry::TYPES[$type] ?? null;

            if (! $config) {
                $this->error("Tipo desconocido: {$type} (válidos: ".implode(', ', array_keys(VoucherJobRegistry::TYPES)).')');

                continue;
            }

            $this->reprocessType($type, $config, $dryRun, $since);
        }

        return self::SUCCESS;
    }

    /** @param array{model: class-string, service: class-string, state: string} $config */
    private function reprocessType(string $type, array $config, bool $dryRun, ?string $since): void
    {
        $modelClass = $config['model'];
        $stateField = $config['state'];

        $query = $modelClass::withoutGlobalScope('branch')
            ->where(function (Builder $q) use ($stateField) {
                $q->whereNull($stateField)->orWhereNotIn($stateField, VoucherStates::FINAL_STATES);
            });

        if ($since) {
            // No solo created_at: un lote subido antes de medianoche crea filas con
            // created_at de "ayer" aunque el problema (y el último intento fallido,
            // que sí actualiza updated_at) sea de hoy.
            $query->where(function (Builder $q) use ($since) {
                $q->whereDate('created_at', '>=', $since)
                    ->orWhereDate('updated_at', '>=', $since);
            });
        }

        // Solo aplica a liquidaciones de compra (voucher_type=3); el resto de Shops
        // nunca entra al ciclo de vida electrónico.
        if ($type === 'shop') {
            $query->where('voucher_type', 3);
        }

        // Retención es un flujo opcional sobre Shop — sin serie_retencion nunca se
        // inició, no es "comprobante pendiente".
        if ($type === 'shop_retention') {
            $query->whereNotNull('serie_retencion');
        }

        $stuck = $query->get(['id', 'branch_id']);

        $this->info("[{$type}] {$stuck->count()} comprobante(s) no final(es) encontrado(s).");

        foreach ($stuck as $model) {
            $companyId = Branch::find($model->branch_id)?->company_id;

            if (! $companyId) {
                $this->warn("  #{$model->id}: sin branch/company resoluble, salteado.");

                continue;
            }

            if ($dryRun) {
                $this->line("  #{$model->id} -> encolaría (company {$companyId})");

                continue;
            }

            ProcessVoucherJob::dispatch($type, $model->id, $companyId);
            $this->line("  #{$model->id} -> encolado (company {$companyId})");
        }
    }
}
