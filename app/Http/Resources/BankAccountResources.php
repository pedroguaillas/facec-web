<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResources extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'account_number' => $this->account_number,
                'type' => $this->type,
                'balance' => $this->balance,
            ],
            'bank' => [
                'id' => $this->bank_id,
                'name' => $this->whenLoaded('bank', fn () => $this->bank->name),
            ],
        ];
    }
}
