<?php

namespace App\StaticClasses;

/**
 * Planes de suscripción. GRATUITO tiene el mismo acceso que FINANCIERO —
 * es un trial de 1 mes, no un nivel de features reducido.
 */
class PlanTypes
{
    const GRATUITO = 'GRATUITO';

    const FACTURADOR = 'FACTURADOR';

    const FINANCIERO = 'FINANCIERO';

    /** Reservado — se habilita cuando se construya el módulo de contabilidad. */
    const CONTABILIDAD = 'CONTABILIDAD';

    const ALL = [
        self::GRATUITO,
        self::FACTURADOR,
        self::FINANCIERO,
    ];

    /** Planes que incluyen cartera/CxC/CxP (crédito, anticipos, pagos, saldos). */
    const FINANCIERO_LEVEL = [
        self::GRATUITO,
        self::FINANCIERO,
    ];
}
