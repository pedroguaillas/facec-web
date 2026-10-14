<?php

namespace App\StaticClasses;

/** Cada cuánto se emite una factura recurrente. */
class RecurringFrequencies
{
    const WEEKLY = 'SEMANAL';

    const MONTHLY = 'MENSUAL';

    const BIMONTHLY = 'BIMESTRAL';

    const QUARTERLY = 'TRIMESTRAL';

    const SEMIANNUAL = 'SEMESTRAL';

    const ANNUAL = 'ANUAL';

    /**
     * Meses entre emisiones (SEMANAL se maneja aparte, por semanas).
     *
     * @var array<string, int>
     */
    const MONTHS = [
        self::MONTHLY => 1,
        self::BIMONTHLY => 2,
        self::QUARTERLY => 3,
        self::SEMIANNUAL => 6,
        self::ANNUAL => 12,
    ];

    const ALL = [
        self::WEEKLY,
        self::MONTHLY,
        self::BIMONTHLY,
        self::QUARTERLY,
        self::SEMIANNUAL,
        self::ANNUAL,
    ];
}
