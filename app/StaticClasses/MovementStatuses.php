<?php

namespace App\StaticClasses;

class MovementStatuses
{
    const PENDING = 'PENDIENTE';

    const COMPLETED = 'COMPLETADO';

    const ALL = [self::PENDING, self::COMPLETED];
}
