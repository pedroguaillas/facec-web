<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterResources extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'name' => $this->name,
                'type' => $this->type,
                'status' => $this->status,
            ],
            'responsible' => [
                'id' => $this->responsible_id,
                'name' => $this->whenLoaded('responsible', fn () => $this->responsible->name ?? $this->responsible->user),
                'email' => $this->whenLoaded('responsible', fn () => $this->responsible->email),
            ],
            'active_session' => $this->whenLoaded(
                'activeSession',
                fn () => $this->activeSession ? new CashRegisterSessionResources($this->activeSession) : null,
            ),
        ];
    }
}
