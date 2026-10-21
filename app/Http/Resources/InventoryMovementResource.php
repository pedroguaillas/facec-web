<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del kardex. `document` identifica el comprobante de origen (venta o
 * compra) cuando lo hay; las operaciones manuales se agrupan por `document_uuid`.
 */
class InventoryMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'product_id' => $this->product_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            'type' => $this->type,
            'direction' => $this->direction,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'unit_cost' => $this->unit_cost,
            'previous_balance' => $this->previous_balance,
            'new_balance' => $this->new_balance,
            'average_cost' => $this->average_cost,
            'balance_value' => $this->balance_value,
            'reverses_id' => $this->reverses_id,
            'document_uuid' => $this->document_uuid,
            'document' => $this->model_type ? [
                'type' => $this->model_type,
                'id' => $this->model_id,
                'serie' => $this->whenLoaded('source', fn () => $this->source?->serie),
            ] : null,
            'note' => $this->note,
            'user' => $this->whenLoaded('user', fn () => $this->user?->name),
        ];
    }
}
