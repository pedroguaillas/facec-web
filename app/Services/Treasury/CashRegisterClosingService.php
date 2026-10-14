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
 * Cierre de una caja chica/otro: todo su saldo regresa a la sesión activa de
 * la caja general. La caja "general" nunca se cierra, ni las cajas sin
 * apertura/cierre (`uses_sessions` false: una sola sesión siempre abierta).
 */
class CashRegisterClosingService
{
    use InteractsWithCashRegisterSessions;

    public function __construct(private MovementService $movements) {}

    /**
     * @param  array<int, array{denomination_id: int, quantity: int}>  $denominations
     */
    public function close(CashRegister $cashRegister, User $user, array $denominations = []): CashRegisterSession
    {
        if ($cashRegister->type === CashRegisterTypes::GENERAL) {
            throw new \RuntimeException('La caja general no se puede cerrar.');
        }

        if (! $cashRegister->uses_sessions) {
            throw new \RuntimeException('Esta caja no usa apertura y cierre. Actívalo en la configuración de la caja.');
        }

        $session = $cashRegister->activeSession;

        if (! $session) {
            throw new \RuntimeException('La caja no tiene una sesión abierta.');
        }

        return DB::transaction(function () use ($cashRegister, $session, $user, $denominations) {
            $this->storeDenominations($session, $denominations, DenominationMoments::CLOSING);

            // Saldo que arroja el arqueo al momento del cierre — se guarda en
            // final_balance ANTES de mover el dinero, porque el movimiento deja
            // current_balance en 0.
            $finalBalance = $session->current_balance;

            if ($finalBalance > 0) {
                $generalSession = $this->activeGeneralSession($cashRegister->branch_id);

                $this->movements->create([
                    'branch_id' => $cashRegister->branch_id,
                    'date' => now()->format('Y-m-d'),
                    'type' => MovementTypes::EXPENSE,
                    'transaction_type' => MovementTransactionTypes::CASH_REGISTER_CLOSING,
                    'source_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
                    'source_id' => $session->id,
                    'destination_type' => MovementPartyRegistry::CASH_REGISTER_SESSION,
                    'destination_id' => $generalSession->id,
                    'amount' => $finalBalance,
                    'user_id' => $user->id,
                ]);
            }

            $session->update([
                'closed_by' => $user->id,
                'closed_at' => now(),
                'final_balance' => $finalBalance,
                'status' => CashRegisterStatuses::CLOSED,
            ]);

            $cashRegister->update(['status' => CashRegisterStatuses::CLOSED]);

            return $session->fresh();
        });
    }
}
