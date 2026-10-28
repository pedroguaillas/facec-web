<?php

namespace App\Services\Treasury;

use App\Models\Treasury\Movement;
use App\Models\Treasury\PaymentReceipt;
use App\StaticClasses\MovementCategories;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementStatuses;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Motor genérico de movimientos: crea el registro de auditoría y aplica su
 * impacto de saldo sobre el origen (y destino, si aplica). Los flujos de
 * negocio por tipo (depósito, retiro, transferencia, apertura/cierre de caja)
 * construyen el array de datos y delegan aquí el efecto sobre los saldos.
 */
class MovementService
{
    /**
     * `receipt_id` (opcional) vincula un PaymentReceipt ya subido: el
     * movimiento toma su archivo en `receipt_path`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Movement
    {
        return DB::transaction(function () use ($data) {
            $receipt = $this->pendingReceipt($data['receipt_id'] ?? null);
            unset($data['receipt_id']);

            $movement = Movement::create([
                ...$data,
                ...($receipt ? ['receipt_path' => $receipt->path] : []),
                'category' => array_key_exists('category', $data)
                    ? $data['category']
                    : MovementCategories::automaticFor($data['transaction_type']),
                'status' => $this->resolveInitialStatus($data),
            ]);

            $receipt?->update(['movement_id' => $movement->id]);

            if ($movement->status === MovementStatuses::COMPLETED) {
                $this->apply($movement);
            }

            return $movement->fresh();
        });
    }

    /**
     * Corrige después el comprobante de un movimiento por banco. Solo toca
     * las claves presentes: `receipt_number` null/"" lo borra; `receipt_id`
     * reemplaza el archivo (null lo quita). El archivo anterior se borra del
     * disco recién confirmada la transacción.
     *
     * @param  array{receipt_id?: int|null, receipt_number?: string|null}  $data
     */
    public function updateReceipt(Movement $movement, array $data): Movement
    {
        $previousPath = $movement->receipt_path;

        DB::transaction(function () use ($movement, $data) {
            if (array_key_exists('receipt_number', $data)) {
                $movement->receipt_number = $data['receipt_number'] ?: null;
            }

            if (array_key_exists('receipt_id', $data)) {
                $receipt = $this->pendingReceipt($data['receipt_id']);

                PaymentReceipt::where('movement_id', $movement->id)->delete();
                $movement->receipt_path = $receipt?->path;
                $receipt?->update(['movement_id' => $movement->id]);
            }

            $movement->save();
        });

        if ($previousPath && $previousPath !== $movement->receipt_path) {
            Storage::delete($previousPath);
        }

        return $movement;
    }

    private function pendingReceipt(mixed $receiptId): ?PaymentReceipt
    {
        if (! $receiptId) {
            return null;
        }

        $receipt = PaymentReceipt::whereKey($receiptId)->whereNull('movement_id')->lockForUpdate()->first();

        if (! $receipt) {
            throw new \RuntimeException('El comprobante no existe o ya está vinculado a otro movimiento.');
        }

        return $receipt;
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
