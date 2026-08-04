<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralGuideResources extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'atts' => [
                'date_start' => $this->date_start,
                'date_end' => $this->date_end,
                'serie' => $this->serie,
                'state' => $this->state,
                'xml' => $this->xml,
                'extra_detail' => $this->extra_detail,
            ],
            'customer' => [
                'name' => $this->name,
            ],
            'carrier' => [
                'name' => $this->carrier_name,
            ],
        ];
    }
}
