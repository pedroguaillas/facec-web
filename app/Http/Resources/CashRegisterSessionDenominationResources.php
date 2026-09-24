<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterSessionDenominationResources extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'denomination_id' => $this->denomination_id,
            'value' => $this->whenLoaded('denomination', fn () => $this->denomination->value),
            'denomination_type' => $this->whenLoaded('denomination', fn () => $this->denomination->type),
            'quantity' => $this->quantity,
        ];
    }
}
