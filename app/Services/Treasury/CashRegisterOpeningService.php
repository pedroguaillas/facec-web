<?php

namespace App\Services\Treasury;

use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\User;
use App\Services\Treasury\Concerns\InteractsWithCashRegisterSessions;
use App\StaticClasses\CashRegisterStatuses;
use App\StaticClasses\CashRegisterTypes;
use App\StaticClasses\DenominationMoments;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use Illuminate\Support\Facades\DB;

/**
 * Apertura de una caja: crea su CashRegisterSession. La caja "general" se
 * abre directo (no tiene de dónde tomar el saldo inicial); chica/otro toman
 * el saldo inicial de la sesión activa de la caja general (valida saldo
 * suficiente vía MovementService).
 */
class CashRegisterOpeningService
{
    use InteractsWithCashRegisterSessions;

    public function __construct(private MovementService $movements) {}

    /**
     * @param  array<int, array{denomination_id: int, quantity: int}>  $denominations
     */
    public function open(CashRegister $cashRegister, float $initialBalance, User $user, array $denominations = []): CashRegisterSession
    {
        if ($cashRegister->status === CashRegisterStatuses::OPEN || $cashRegister->activeSession()->exists()) {
            throw new \RuntimeException('La caja ya tiene una sesión abierta.');
        }

        return DB::transaction(function () use ($cashRegister, $initialBalance, $user, $denominations) {
            $session = CashRegisterSession::create([
                'branch_id' => $cashRegister->branch_id,
                'cash_register_id' => $cashRegister->id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'initial_balance' => $initialBalance,
                'current_balance' => 0,
                'status' => CashRegisterStatuses::OPEN,
            ]);

            if ($cashRegister->type === CashRegisterTypes::GENERAL) {
                $session->update(['current_balance' => $initialBalance]);
            } elseif ($initialBalance > 0) {
                $generalSession = $this->activeGeneralSession($cashRegister->branch_id);

                $this->movements->create([
                    'branch_id' => $cashRegister->branch_id,
                    'date' => now()->format('Y-m-d'),
                    'type' => MovementTypes::EXPENSE,
                    'transaction_type' => MovementTransactionTypes::CASH_REGISTER_OPENING,
                    'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
                    'source_id' => $generalSession->id,
                    'destination_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
                    'destination_id' => $session->id,
                    'amount' => $initialBalance,
                    'user_id' => $user->id,
                ]);
            }

            $cashRegister->update(['status' => CashRegisterStatuses::OPEN]);

            $this->storeDenominations($session, $denominations, DenominationMoments::OPENING);

            return $session->fresh();
        });
    }
}
