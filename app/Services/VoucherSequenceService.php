<?php

namespace App\Services;

use App\Models\EmisionPoint;
use App\Models\EmisionPointSequence;
use App\Models\VoucherType;

class VoucherSequenceService
{
    /**
     * Reserva (lee e incrementa atómicamente) el siguiente número secuencial
     * de un punto de emisión para un tipo de comprobante dado.
     *
     * Debe invocarse dentro de una transacción de BD abierta por el caller,
     * para que el lockForUpdate() sea efectivo.
     */
    public function reserveNext(EmisionPoint $emisionPoint, string $voucherTypeSlug): int
    {
        $voucherType = VoucherType::where('slug', $voucherTypeSlug)->firstOrFail();

        EmisionPointSequence::firstOrCreate(
            ['emision_point_id' => $emisionPoint->id, 'voucher_type_id' => $voucherType->id],
            ['next_number' => 1]
        );

        $sequence = EmisionPointSequence::where('emision_point_id', $emisionPoint->id)
            ->where('voucher_type_id', $voucherType->id)
            ->lockForUpdate()
            ->first();

        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return $number;
    }

    /**
     * Reserva el siguiente número y arma la serie completa
     * "estab(3)-ptoEmi(3)-secuencial(9)". El prefijo estab-ptoEmi sale del
     * propio punto de emisión (branch->store + point) — no depende de nada
     * que mande el cliente, `point_id` ya alcanza.
     */
    public function generateSerie(EmisionPoint $emisionPoint, string $voucherTypeSlug): string
    {
        $number = $this->reserveNext($emisionPoint, $voucherTypeSlug);

        $prefix = sprintf('%03d-%03d-', (int) $emisionPoint->branch->store, (int) $emisionPoint->point);

        return $prefix.str_pad((string) $number, 9, '0', STR_PAD_LEFT);
    }
}
