<?php

namespace App\Services\Treasury;

use App\Models\Treasury\BankAccount;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\Movement;
use App\Models\User;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;

/** Depósito: caja → banco. Resta en la sesión de caja, suma en la cuenta bancaria. */
class DepositService
{
    public function __construct(private MovementService $movements) {}

    /**
     * @param  array<string, mixed>  $extra  description, receipt_number, receipt_path, payment_method, date
     */
    public function deposit(CashRegisterSession $session, BankAccount $bankAccount, float $amount, User $user, array $extra = []): Movement
    {
        return $this->movements->create([
            'branch_id' => $session->branch_id,
            'date' => $extra['date'] ?? now()->format('Y-m-d'),
            'type' => MovementTypes::EXPENSE,
            'transaction_type' => MovementTransactionTypes::DEPOSIT,
            'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
            'source_id' => $session->id,
            'destination_type' => MovementPartyRegistry::BANK_ACCOUNT,
            'destination_id' => $bankAccount->id,
            'amount' => $amount,
            'user_id' => $user->id,
            'payment_method' => $extra['payment_method'] ?? PaymentMethods::CASH,
            'description' => $extra['description'] ?? null,
            'receipt_number' => $extra['receipt_number'] ?? null,
            'receipt_path' => $extra['receipt_path'] ?? null,
        ]);
    }
}
