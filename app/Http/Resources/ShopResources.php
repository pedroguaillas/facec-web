<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopResources extends JsonResource
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
                'voucher_type' => $this->voucher_type,
                'serie' => $this->serie,
                'total' => $this->total,
                // Retención
                'serie_retencion' => $this->serie_retencion,
                'state_retencion' => $this->state_retencion,
                'xml_retention' => $this->xml_retention,
                'send_mail_retention' => $this->send_mail_retention,
                'extra_detail_retention' => $this->extra_detail_retention,
                // Suma de shop_retention_items.value — iva_retention/rent_retention en `shops` nunca se pueblan.
                'retention' => (float) ($this->retention_sum ?? 0),
                // Liquidación en compra
                'state' => $this->state,
                'xml' => $this->xml,
                'extra_detail' => $this->extra_detail,
            ],
            'provider' => [
                'name' => $this->name,
                'email' => $this->email,
            ],
        ];
    }
}
