<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringInvoiceResources extends JsonResource
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
                'frequency' => $this->frequency,
                'start_at' => $this->start_at?->format('Y-m-d H:i'),
                'end_date' => $this->end_date?->format('Y-m-d'),
                'max_occurrences' => $this->max_occurrences,
                'next_run_at' => $this->next_run_at?->format('Y-m-d H:i'),
                'status' => $this->status,
                'auto_send' => $this->auto_send,
                'payment_type' => $this->payment_type,
                'due_days' => $this->due_days,
                'pay_method' => $this->pay_method,
                'source_type' => $this->source_type,
                'source_id' => $this->source_id,
                'emision_point_id' => $this->emision_point_id,
                'description' => $this->description,
                'aditionals' => $this->aditionals ?? [],
                'emitted_count' => $this->emitted_count,
                'last_run_at' => $this->last_run_at?->format('Y-m-d H:i'),
                'last_error' => $this->last_error,
                'subtotal' => $this->whenLoaded('items', fn () => round($this->items->sum(
                    fn ($item) => $item->quantity * $item->price - $item->discount,
                ), 2)),
            ],
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'identication' => $this->customer->identication,
                'email' => $this->customer->email,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product?->code,
                'name' => $item->product?->name,
                'code' => $item->code,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount,
                'iva' => $item->iva,
            ])),
        ];
    }
}
