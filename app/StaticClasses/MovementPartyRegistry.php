<?php

namespace App\StaticClasses;

use App\Models\Order\Order;
use App\Models\Shop\Shop;
use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegisterSession;

/**
 * Modelos que pueden ser origen/destino de un Movement, su alias de morph map
 * (usado también como source_type/destination_type en BD y en la API) y el
 * campo donde vive su saldo corriente.
 */
class MovementPartyRegistry
{
    const BANK_ACCOUNT = 'bank_account';

    const CASH_REGISTER_SESSION = 'cash_register_session';

    /**
     * La caja en sí (no su sesión): para referenciarla donde la sesión cambia
     * con el tiempo (resumen de cuentas, cuenta de cobro de una factura
     * recurrente). No es origen/destino de un Movement.
     */
    const CASH_REGISTER = 'cash_register';

    /**
     * @var array<string, array{model: class-string, balance_field: string}>
     */
    const TYPES = [
        self::BANK_ACCOUNT => ['model' => BankAccount::class, 'balance_field' => 'balance'],
        self::CASH_REGISTER_SESSION => ['model' => CashRegisterSession::class, 'balance_field' => 'current_balance'],
    ];

    /** Documento que originó el movimiento (`origin_type`): venta o compra. */
    const ORDER = 'order';

    const SHOP = 'shop';

    /**
     * @var array<string, class-string>
     */
    const ORIGINS = [
        self::ORDER => Order::class,
        self::SHOP => Shop::class,
    ];

    /**
     * @return array<string, class-string>
     */
    public static function morphMap(): array
    {
        return [
            ...array_map(fn (array $type) => $type['model'], self::TYPES),
            ...self::ORIGINS,
        ];
    }
}
