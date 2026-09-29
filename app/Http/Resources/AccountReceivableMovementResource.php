<?php

namespace App\Http\Resources;

use App\StaticClasses\AccountsReceivableMovementTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountReceivableMovementResource extends JsonResource
{
    private const TYPE_LABELS = [
        AccountsReceivableMovementTypes::FACTURA => 'Factura',
        AccountsReceivableMovementTypes::NOTA_CREDITO => 'Nota de crédito',
        AccountsReceivableMovementTypes::PAGO => 'Abono',
        AccountsReceivableMovementTypes::SALDO_INICIAL => 'Saldo inicial',
        AccountsReceivableMovementTypes::ANTICIPO => 'Anticipo',
        AccountsReceivableMovementTypes::APLICACION_ANTICIPO => 'Aplicación de anticipo',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPE_LABELS[$this->type] ?? $this->type,
            'description' => $this->description,
            'debe' => $this->debe,
            'haber' => $this->haber,
            'previous_balance' => $this->previous_balance,
            'new_balance' => $this->new_balance,
            'date' => $this->date?->format('Y-m-d'),
            'order_id' => $this->order_id,
        ];
    }
}
