<?php

namespace App\Models\Product;

use Database\Factories\Product\ProductDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valor de una característica (ProductTemplateField) para un producto.
 */
class ProductDetail extends Model
{
    /** @use HasFactory<ProductDetailFactory> */
    use HasFactory;

    protected static function newFactory(): ProductDetailFactory
    {
        return ProductDetailFactory::new();
    }

    protected $fillable = [
        'product_id',
        'product_template_field_id',
        'value',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductTemplateField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductTemplateField::class, 'product_template_field_id');
    }
}
