<?php

namespace App\Http\Resources;

use App\Models\Order\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResources extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'date' => $this->date,
                'serie' => "{$this->voucherType->abbreviation} {$this->serie}",
                'state' => $this->state,
                'total' => $this->total,
                'paid' => $this->paid,
                'payment_type' => $this->payment_type,
                'xml' => $this->xml,
                'send_mail' => $this->send_mail,
                'extra_detail' => $this->extra_detail,
                // Proforma ya convertida: su factura vigente {id, serie, state}; null si no.
                'converted_invoice' => $this->relationLoaded('convertedInvoice') ? $this->convertedInvoiceSummary() : null,
            ],
            'customer' => [
                'name' => $this->name,
                'email' => $this->email,
            ],
        ];
    }
}
