<?php

namespace App\StaticClasses;

class CashRegisterTypes
{
    const GENERAL = 'GENERAL';

    const PETTY = 'CHICA';

    const OTHER = 'OTRO';

    const ALL = [self::GENERAL, self::PETTY, self::OTHER];
}
