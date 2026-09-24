<?php

namespace App\StaticClasses;

class PaymentMethods
{
    const CASH = 'EFECTIVO';

    const DEPOSIT = 'DEPOSITO';

    const TRANSFER = 'TRANSFERENCIA';

    const CHECK = 'CHEQUE';

    const ALL = [self::CASH, self::DEPOSIT, self::TRANSFER, self::CHECK];
}
