<?php

namespace App\StaticClasses;

/** Dirección del movimiento respecto al origen: INGRESO suma su saldo, EGRESO lo resta. */
class MovementTypes
{
    const INCOME = 'INGRESO';

    const EXPENSE = 'EGRESO';

    const ALL = [self::INCOME, self::EXPENSE];
}
