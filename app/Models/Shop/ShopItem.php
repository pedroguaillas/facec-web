<?php

namespace App\Models\Shop;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;

class ShopItem extends Model
{
    protected $fillable = [
        'shop_id', 'product_id', 'iva',
        // Presentación comprada y copia de su factor (1 = unidad base).
        'presentation_id', 'conversion_factor',
        'quantity', 'price', 'discount',
    ];

    protected $casts = [
        'conversion_factor' => 'float',
        'iva' => 'float',
        'price' => 'float',
        'discount' => 'float',
        'quantity' => 'float',
    ];

    /**
     * Get the post that owns the comment.
     */
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the post that owns the comment.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
