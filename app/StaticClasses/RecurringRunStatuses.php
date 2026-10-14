<?php

namespace App\StaticClasses;

class RecurringRunStatuses
{
    const ISSUED = 'EMITIDA';

    /** No se pudo emitir; se reintenta en la próxima corrida diaria. */
    const FAILED = 'ERROR';

    const ALL = [self::ISSUED, self::FAILED];
}
