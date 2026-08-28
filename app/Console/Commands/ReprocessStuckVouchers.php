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

#[Signature('vouchers:reprocess-stuck {--type=* : order|shop|referral_guide|shop_retention (por defecto: order)} {--dry-run : Solo listar, no encolar}')]
#[Description('Reencola ProcessVoucherJob para todos los comprobantes que quedaron en un estado no final (CREADO, DEVUELTA, etc.) — para reprocesar en bloque en vez de uno por uno desde la interfaz.')]
class ReprocessStuckVouchers extends Command
{
    public function handle(): int
    {
        $types = $this->option('type') ?: ['order'];
        $dryRun = (bool) $this->option('dry-run');

        foreach ($types as $type) {
            $config = VoucherJobRegistry::TYPES[$type] ?? null;

            if (! $config) {
                $this->error("Tipo desconocido: {$type} (válidos: ".implode(', ', array_keys(VoucherJobRegistry::TYPES)).')');

                continue;
            }

            $this->reprocessType($type, $config, $dryRun);
        }

        return self::SUCCESS;
    }

    /** @param array{model: class-string, service: class-string, state: string} $config */
    private function reprocessType(string $type, array $config, bool $dryRun): void
    {
        $modelClass = $config['model'];
        $stateField = $config['state'];

        $query = $modelClass::withoutGlobalScope('branch')
            ->where(function (Builder $q) use ($stateField) {
                $q->whereNull($stateField)->orWhereNotIn($stateField, VoucherStates::FINAL_STATES);
            });

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
