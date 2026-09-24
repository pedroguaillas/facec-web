<?php

namespace App\StaticClasses;

/** Momento del arqueo de caja: conteo de denominaciones al abrir o al cerrar la sesión. */
class DenominationMoments
{
    const OPENING = 'APERTURA';

    const CLOSING = 'CIERRE';

    const ALL = [self::OPENING, self::CLOSING];
}
