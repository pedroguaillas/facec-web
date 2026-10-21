<?php

namespace App\Models\Product;

use Database\Factories\Product\ProductPresentationFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'active',
    ];

    protected $casts = [
        'conversion_factor' => 'float',
        'price' => 'float',
        'active' => 'boolean',
    ];

    /**
     * Solo las que se pueden usar en ventas/compras nuevas.
     *
     * @param  Builder<ProductPresentation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
