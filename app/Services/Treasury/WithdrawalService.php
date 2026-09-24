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

/** Retiro: banco → caja. Resta en la cuenta bancaria, suma en la sesión de caja. */
class WithdrawalService
{
    public function __construct(private MovementService $movements) {}

    /**
     * @param  array<string, mixed>  $extra  description, receipt_number, receipt_path, payment_method, date
     */
    public function withdraw(BankAccount $bankAccount, CashRegisterSession $session, float $amount, User $user, array $extra = []): Movement
    {
        return $this->movements->create([
            'branch_id' => $bankAccount->branch_id,
            'date' => $extra['date'] ?? now()->format('Y-m-d'),
            'type' => MovementTypes::EXPENSE,
            'transaction_type' => MovementTransactionTypes::WITHDRAWAL,
            'source_type' => MovementPartyRegistry::BANK_ACCOUNT,
            'source_id' => $bankAccount->id,
            'destination_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
            'destination_id' => $session->id,
            'amount' => $amount,
            'user_id' => $user->id,
            'payment_method' => $extra['payment_method'] ?? PaymentMethods::CASH,
            'description' => $extra['description'] ?? null,
            'receipt_number' => $extra['receipt_number'] ?? null,
            'receipt_path' => $extra['receipt_path'] ?? null,
        ]);
    }
}
