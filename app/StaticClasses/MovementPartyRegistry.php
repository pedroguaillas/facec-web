<?php

namespace App\StaticClasses;

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
     * @var array<string, array{model: class-string, balance_field: string}>
     */
    const TYPES = [
        self::BANK_ACCOUNT => ['model' => BankAccount::class, 'balance_field' => 'balance'],
        self::CASH_REGISTER_SESSION => ['model' => CashRegisterSession::class, 'balance_field' => 'current_balance'],
    ];

    /**
     * @return array<string, class-string>
     */
    public static function morphMap(): array
    {
        return array_map(fn (array $type) => $type['model'], self::TYPES);
    }
}
