<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->name,
            'conversion_factor' => $this->conversion_factor,
            'price' => $this->price ?? round($this->product->price1 * $this->conversion_factor, 6),
            'code' => $this->code,
            'active' => $this->active,
        ];
    }
}
