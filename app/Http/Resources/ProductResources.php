<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResources extends JsonResource
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
                'code' => $this->code,
                'aux_cod' => $this->aux_cod,
                'type_product' => $this->type_product,
                'name' => $this->name,
                'price1' => $this->price1,
                'iva' => $this->percentage,
                'ice' => $this->ice,
                'irbpnr' => $this->irbpnr,
                'stock' => $this->stock,
                'min_stock' => $this->min_stock,
                'differentiated_iva' => $this->differentiated_iva,
            ],
            // Unidad (moto) de un producto modelo; null si no lo es.
            'parent_product_id' => $this->parent_product_id,
            // Producto modelo con plantilla: en compras se registra la unidad (ver docs/caracteristicas-producto.md).
            'product_template_id' => $this->product_template_id,
            'iva' => [
                'code' => $this->iva_code,
                'percentage' => $this->percentage,
            ],
            // Mismo formato que products/lookup; precio propio o price1 × factor.
            'presentations' => $this->whenLoaded('presentations', fn () => $this->presentations->map(fn ($presentation) => [
                'id' => $presentation->id,
                'name' => $presentation->name,
                'code' => $presentation->code,
                'conversion_factor' => $presentation->conversion_factor,
                'price' => $presentation->price ?? round((float) $this->price1 * $presentation->conversion_factor, 6),
            ])->values()),
            // Características efectivas (modelo + unidad), mismo formato que products/lookup.
            'characteristics' => $this->whenLoaded('details', fn () => $this->characteristics()),
            // 'category' => [
            //     'category_id' => $this->category_id,
            //     'category' => $this->category,
            // ],
            // 'unity' => [
            //     'unity_id' => $this->unity_id,
            //     'unity' => $this->unity
            // ]
        ];
    }
}
