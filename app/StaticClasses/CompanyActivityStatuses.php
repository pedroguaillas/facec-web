<?php

namespace App\StaticClasses;

/**
 * Actividad de un contribuyente en el listado del admin (ver
 * CompanyService::getAll): nunca emitió, inactivo (>30 días sin entrar),
 * en riesgo (8–30 días, o entra pero sin comprobantes en 30 días) y activo.
 */
class CompanyActivityStatuses
{
    const NEVER = 'never';

    const INACTIVE = 'inactive';

    const RISK = 'risk';

    const ACTIVE = 'active';

    const ALL = [self::NEVER, self::INACTIVE, self::RISK, self::ACTIVE];
}
