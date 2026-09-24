<?php

namespace App\StaticClasses;

/** Estados compartidos por CashRegister.status y CashRegisterSession.status. */
class CashRegisterStatuses
{
    const OPEN = 'ABIERTA';

    const CLOSED = 'CERRADA';

    const ALL = [self::OPEN, self::CLOSED];
}
