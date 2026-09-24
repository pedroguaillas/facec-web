<?php

namespace App\Services\Treasury\Concerns;

use App\Models\Treasury\CashRegister;
use App\Models\Treasury\CashRegisterSession;
use App\Models\Treasury\CashRegisterSessionDenomination;
use App\StaticClasses\CashRegisterTypes;

trait InteractsWithCashRegisterSessions
{
    /**
     * Sesión activa de la caja general de la sucursal — origen/destino de las
     * aperturas y cierres de cajas chica/otro.
     */
    private function activeGeneralSession(int $branchId): CashRegisterSession
    {
        $general = CashRegister::where('branch_id', $branchId)
            ->where('type', CashRegisterTypes::GENERAL)
            ->first();

        if (! $general || ! $general->activeSession) {
            throw new \RuntimeException('No existe una caja general con sesión abierta para tomar/recibir el saldo.');
        }

        return $general->activeSession;
    }

    /**
     * @param  array<int, array{denomination_id: int, quantity: int}>  $denominations
     */
    private function storeDenominations(CashRegisterSession $session, array $denominations, string $moment): void
    {
        foreach ($denominations as $row) {
            CashRegisterSessionDenomination::create([
                'cash_register_session_id' => $session->id,
                'denomination_id' => $row['denomination_id'],
                'quantity' => $row['quantity'],
                'moment' => $moment,
            ]);
        }
    }
}
