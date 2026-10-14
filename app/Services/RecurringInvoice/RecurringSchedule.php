<?php

namespace App\Services\RecurringInvoice;

use App\StaticClasses\RecurringFrequencies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fechas de emisión (con hora) de una factura recurrente: la n-ésima es
 * `start_at` + n períodos, a la misma hora. Los meses no desbordan (inicio
 * el 31 → 28/29 en febrero y vuelve al 31 en marzo, porque siempre se
 * cuenta desde el ancla).
 */
class RecurringSchedule
{
    /** Tope de iteraciones (≈ 20 años semanales) para no colgarse con datos raros. */
    private const MAX_OCCURRENCES = 1100;

    public function occurrence(CarbonInterface $start, string $frequency, int $index): CarbonImmutable
    {
        $start = CarbonImmutable::parse($start)->startOfMinute();

        if ($frequency === RecurringFrequencies::WEEKLY) {
            return $start->addWeeks($index);
        }

        return $start->addMonthsNoOverflow($index * RecurringFrequencies::MONTHS[$frequency]);
    }

    /** Primera emisión >= $moment (o null si pasa el tope). */
    public function firstOnOrAfter(CarbonInterface $start, string $frequency, CarbonInterface $moment): ?CarbonImmutable
    {
        return $this->first($start, $frequency, fn (CarbonImmutable $occurrence) => $occurrence->gte($moment));
    }

    /** Primera emisión > $moment. */
    public function firstAfter(CarbonInterface $start, string $frequency, CarbonInterface $moment): ?CarbonImmutable
    {
        return $this->first($start, $frequency, fn (CarbonImmutable $occurrence) => $occurrence->gt($moment));
    }

    /** Última emisión <= $moment (null si $moment es anterior al inicio). */
    public function lastOnOrBefore(CarbonInterface $start, string $frequency, CarbonInterface $moment): ?CarbonImmutable
    {
        $last = null;

        for ($index = 0; $index <= self::MAX_OCCURRENCES; $index++) {
            $occurrence = $this->occurrence($start, $frequency, $index);

            if ($occurrence->gt($moment)) {
                break;
            }

            $last = $occurrence;
        }

        return $last;
    }

    /**
     * @param  callable(CarbonImmutable): bool  $matches
     */
    private function first(CarbonInterface $start, string $frequency, callable $matches): ?CarbonImmutable
    {
        for ($index = 0; $index <= self::MAX_OCCURRENCES; $index++) {
            $occurrence = $this->occurrence($start, $frequency, $index);

            if ($matches($occurrence)) {
                return $occurrence;
            }
        }

        return null;
    }
}
