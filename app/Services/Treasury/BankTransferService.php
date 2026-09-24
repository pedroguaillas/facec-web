<?php

namespace App\Services\Treasury;

use App\Models\Treasury\BankAccount;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;

/** Transferencia entre dos cuentas bancarias: resta en origen, suma en destino. */
class BankTransferService
{
    public function __construct(private MovementService $movements) {}

    /**
     * @param  array<string, mixed>  $extra  description, receipt_number, receipt_path, payment_method, date
     */
    public function transfer(BankAccount $origin, BankAccount $destination, float $amount, User $user, array $extra = []): Movement
    {
        if ($origin->is($destination)) {
            throw new \RuntimeException('El origen y el destino de la transferencia no pueden ser la misma cuenta.');
        }

        return $this->movements->create([
            'branch_id' => $origin->branch_id,
            'date' => $extra['date'] ?? now()->format('Y-m-d'),
            'type' => MovementTypes::EXPENSE,
            'transaction_type' => MovementTransactionTypes::TRANSFER,
            'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
            'source_id' => $origin->id,
            'destination_type' => MovementPartyRegistry::BANK_ACCOUNT,
            'destination_id' => $destination->id,
            'amount' => $amount,
            'user_id' => $user->id,
            'payment_method' => $extra['payment_method'] ?? PaymentMethods::TRANSFER,
            'description' => $extra['description'] ?? null,
            'receipt_number' => $extra['receipt_number'] ?? null,
            'receipt_path' => $extra['receipt_path'] ?? null,
        ]);
    }
}
