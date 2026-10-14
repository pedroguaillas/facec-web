<?php

namespace App\Services\Treasury;

use App\Models\Treasury\Movement;
use App\StaticClasses\MovementCategories;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Motor genérico de movimientos: crea el registro de auditoría y aplica su
 * impacto de saldo sobre el origen (y destino, si aplica). Los flujos de
 * negocio por tipo (depósito, retiro, transferencia, apertura/cierre de caja)
 * construyen el array de datos y delegan aquí el efecto sobre los saldos.
 */
class MovementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Movement
    {
        return DB::transaction(function () use ($data) {
            $movement = Movement::create([
                ...$data,
                'category' => array_key_exists('category', $data)
                    ? $data['category']
                    : MovementCategories::automaticFor($data['transaction_type']),
                'status' => $this->resolveInitialStatus($data),
            ]);

            if ($movement->status === MovementStatuses::COMPLETED) {
                $this->apply($movement);
            }

            return $movement->fresh();
        });
    }

    /**
     * Cobros/pagos con cheque quedan PENDIENTE hasta que el cheque se hace
     * efectivo (vía complete()) — no se pueden crear ya COMPLETADO, sin
     * importar lo que venga en $data['status'].
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveInitialStatus(array $data): string
    {
        if (($data['payment_method'] ?? null) === PaymentMethods::CHECK) {
            return MovementStatuses::PENDING;
        }

        return $data['status'] ?? MovementStatuses::COMPLETED;
    }

    /**
     * Aplica el impacto de saldo de un movimiento PENDIENTE y lo marca COMPLETADO.
     */
    public function complete(Movement $movement): Movement
    {
        if ($movement->status === MovementStatuses::COMPLETED) {
            throw new \RuntimeException('El movimiento ya fue completado.');
        }

        return DB::transaction(function () use ($movement) {
            $this->apply($movement);

            return $movement->fresh();
        });
    }

    private function apply(Movement $movement): void
    {
        $source = $this->resolveParty($movement->source_type, $movement->source_id);
        $sourceField = $this->balanceField($movement->source_type);

        $previousBalance = (float) $source->{$sourceField};

        if ($movement->type === MovementTypes::EXPENSE && $previousBalance < $movement->amount) {
            throw new \RuntimeException('Saldo insuficiente en el origen del movimiento.');
        }

        $sourceDelta = $movement->type === MovementTypes::EXPENSE ? -$movement->amount : $movement->amount;

        $source->{$sourceField} = $previousBalance + $sourceDelta;
        $source->save();

        if ($movement->destination_type && $movement->destination_id) {
            $destination = $this->resolveParty($movement->destination_type, $movement->destination_id);
            $destinationField = $this->balanceField($movement->destination_type);

            $destination->{$destinationField} = $destination->{$destinationField} - $sourceDelta;
            $destination->save();
        }

        $movement->forceFill([
            'previous_balance' => $previousBalance,
            'new_balance' => $source->{$sourceField},
            'status' => MovementStatuses::COMPLETED,
        ])->save();
    }

    private function resolveParty(string $type, int $id): Model
    {
        $modelClass = MovementPartyRegistry::TYPES[$type]['model'] ?? null;

        if (! $modelClass) {
            throw new \RuntimeException("Tipo de origen/destino de movimiento no soportado: {$type}");
        }

        return $modelClass::findOrFail($id);
    }

    private function balanceField(string $type): string
    {
        return MovementPartyRegistry::TYPES[$type]['balance_field'];
    }
}
