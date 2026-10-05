<?php

namespace App\Models\Product;

use Database\Factories\Product\ProductPresentationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPresentation extends Model
{
    use HasFactory;

    protected static function newFactory(): ProductPresentationFactory
    {
        return ProductPresentationFactory::new();
    }

    protected $fillable = [
        'product_id',
        'name',
        'conversion_factor',
        'price',
        'code',
    ];

    protected $casts = [
        'conversion_factor' => 'float',
        'price' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
