<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'conversion_factor' => 'float',
        'quantity' => 'float',
        'price' => 'float',
        'discount' => 'float',
        'ice' => 'float',
        'details' => 'array',
    ];

    protected $fillable = [
        'order_id', 'product_id',
        // Presentación vendida y copia de su factor (1 = unidad base).
        'presentation_id', 'conversion_factor',
        // Código/descripción propios de la línea (null = los del producto).
        'code', 'description',
        // Foto de las características del producto al vender (null = sin características).
        'details',
        'quantity', 'price',
        'discount', 'iva', 'ice',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
