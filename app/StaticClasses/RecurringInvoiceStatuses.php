<?php

namespace App\StaticClasses;

class RecurringInvoiceStatuses
{
    const ACTIVE = 'ACTIVA';

    const PAUSED = 'PAUSADA';

    /** Llegó a su fecha fin o número de emisiones; no se reactiva. */
    const FINISHED = 'FINALIZADA';

    const ALL = [self::ACTIVE, self::PAUSED, self::FINISHED];
}
