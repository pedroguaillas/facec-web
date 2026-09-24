<?php

namespace App\StaticClasses;

class BankAccountTypes
{
    const SAVINGS = 'AHORRO';

    const CHECKING = 'CORRIENTE';

    const ALL = [self::SAVINGS, self::CHECKING];
}
