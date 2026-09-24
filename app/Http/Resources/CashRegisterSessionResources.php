<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterSessionResources extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'opened_at' => $this->opened_at?->format('d-m-Y H:i'),
                'closed_at' => $this->closed_at?->format('d-m-Y H:i'),
                'initial_balance' => $this->initial_balance,
                'current_balance' => $this->current_balance,
                'final_balance' => $this->final_balance,
                'status' => $this->status,
            ],
            'cash_register_id' => $this->cash_register_id,
            'opened_by' => $this->opened_by,
            'closed_by' => $this->closed_by,
            'opening_denominations' => CashRegisterSessionDenominationResources::collection($this->whenLoaded('openingDenominations')),
            'closing_denominations' => CashRegisterSessionDenominationResources::collection($this->whenLoaded('closingDenominations')),
        ];
    }
}
