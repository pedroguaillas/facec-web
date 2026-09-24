<?php

namespace App\Http\Resources;

use App\StaticClasses\MovementPartyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementResources extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'date' => $this->date?->format('Y-m-d'),
                'type' => $this->type,
                'transaction_type' => $this->transaction_type,
                'amount' => $this->amount,
                'previous_balance' => $this->previous_balance,
                'new_balance' => $this->new_balance,
                'status' => $this->status,
                'receipt_number' => $this->receipt_number,
                'payment_method' => $this->payment_method,
                'description' => $this->description,
                'receipt_path' => $this->receipt_path,
            ],
            'source_type' => $this->source_type,
            'source' => $this->whenLoaded('source', fn () => $this->morphResource($this->source, $this->source_type)),
            'destination_type' => $this->destination_type,
            'destination' => $this->whenLoaded('destination', fn () => $this->morphResource($this->destination, $this->destination_type)),
            'user_id' => $this->user_id,
        ];
    }

    private function morphResource(?Model $model, ?string $type): BankAccountResources|CashRegisterSessionResources|null
    {
        if (! $model || ! $type) {
            return null;
        }

        return match ($type) {
            MovementPartyRegistry::BANK_ACCOUNT => new BankAccountResources($model),
            MovementPartyRegistry::CASH_REGISTER_SESSION => new CashRegisterSessionResources($model),
            default => null,
        };
    }
}
