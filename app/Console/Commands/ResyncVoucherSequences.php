<?php

namespace App\Console\Commands;

use App\Models\EmisionPointSequence;
use App\StaticClasses\VoucherTypes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('vouchers:resync-sequences {--dry-run : Solo listar, no actualizar}')]
#[Description('Sube emision_point_sequences.next_number al máximo secuencial ya usado + 1 cuando quedó atrasado (p. ej. por la carga por lote que usaba la columna legacy emision_points.invoice). Nunca baja un contador.')]
class ResyncVoucherSequences extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        EmisionPointSequence::with(['emisionPoint.branch', 'voucherType'])
            ->chunkById(500, function ($sequences) use ($dryRun, &$rows) {
                foreach ($sequences as $sequence) {
                    $maxUsed = $this->maxUsedNumber($sequence);

                    if ($maxUsed === null || $maxUsed < $sequence->next_number) {
                        continue;
                    }

                    $rows[] = [
                        $sequence->emisionPoint->branch_id,
                        $sequence->emisionPoint->point,
                        $sequence->voucherType->slug,
                        $sequence->next_number,
                        $maxUsed + 1,
                    ];

                    if (! $dryRun) {
                        DB::transaction(function () use ($sequence, $maxUsed) {
                            $locked = EmisionPointSequence::whereKey($sequence->id)->lockForUpdate()->first();

                            if ($locked->next_number <= $maxUsed) {
                                $locked->update(['next_number' => $maxUsed + 1]);
                            }
                        });
                    }
                }
            });

        if ($rows === []) {
            $this->info('Todas las secuencias están al día.');

            return self::SUCCESS;
        }

        $this->table(['branch_id', 'punto', 'tipo', 'next_number actual', 'next_number nuevo'], $rows);
        $this->info(($dryRun ? '[dry-run] ' : '').count($rows).' secuencia(s) atrasada(s)'.($dryRun ? '.' : ' actualizada(s).'));

        return self::SUCCESS;
    }

    /**
     * Máximo secuencial ya emitido para el estab-ptoEmi y tipo de comprobante
     * de la secuencia, buscando en la tabla donde vive cada tipo.
     */
    private function maxUsedNumber(EmisionPointSequence $sequence): ?int
    {
        $emisionPoint = $sequence->emisionPoint;
        $slug = $sequence->voucherType->slug;
        $prefix = sprintf('%03d-%03d-', (int) $emisionPoint->branch->store, (int) $emisionPoint->point);

        [$table, $column, $filterByVoucherType] = match (true) {
            in_array($slug, VoucherTypes::VENTA_SLUGS, true) => ['orders', 'serie', true],
            $slug === VoucherTypes::COMPRA_LIQUIDACION_COMPRA => ['shops', 'serie', true],
            $slug === VoucherTypes::RETENCION => ['shops', 'serie_retencion', false],
            $slug === VoucherTypes::GUIA_REMISION => ['referral_guides', 'serie', false],
            default => [null, null, false],
        };

        if ($table === null) {
            return null;
        }

        $max = DB::table($table)
            ->where('branch_id', $emisionPoint->branch_id)
            ->where($column, 'like', $prefix.'%')
            ->when($filterByVoucherType, fn ($query) => $query->where('voucher_type_id', $sequence->voucher_type_id))
            ->max(DB::raw("CAST(SUBSTRING({$column}, 9) AS UNSIGNED)"));

        return $max === null ? null : (int) $max;
    }
}
