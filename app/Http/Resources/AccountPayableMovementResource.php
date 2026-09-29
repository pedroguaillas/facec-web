<?php

namespace App\Http\Resources;

use App\StaticClasses\AccountsPayableMovementTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountPayableMovementResource extends JsonResource
{
    private const TYPE_LABELS = [
        AccountsPayableMovementTypes::FACTURA => 'Factura',
        AccountsPayableMovementTypes::RETENCION => 'Retención',
        AccountsPayableMovementTypes::PAGO => 'Abono',
        AccountsPayableMovementTypes::SALDO_INICIAL => 'Saldo inicial',
        AccountsPayableMovementTypes::ANTICIPO => 'Anticipo',
        AccountsPayableMovementTypes::APLICACION_ANTICIPO => 'Aplicación de anticipo',
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
            'shop_id' => $this->shop_id,
        ];
    }
}
