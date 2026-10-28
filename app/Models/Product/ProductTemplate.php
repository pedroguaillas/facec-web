<?php

namespace App\Models\Product;

use App\Models\BaseModel;
use App\Models\Branch;
use Database\Factories\Product\ProductTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de características que configura el contribuyente (ej. "Moto").
 * Ver docs/caracteristicas-producto.md.
 */
class ProductTemplate extends BaseModel
{
    /** @use HasFactory<ProductTemplateFactory> */
    use HasFactory;

    /** Máximo de <detAdicional> por <detalle> que admite el XSD del SRI. */
    const MAX_SRI_FIELDS = 3;

    protected static function newFactory(): ProductTemplateFactory
    {
        return ProductTemplateFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'name',
        'code_prefix',
        'code_field_id',
        'active',
    ];

    protected $casts = [
        'code_field_id' => 'integer',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<ProductTemplateField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ProductTemplateField::class)->orderBy('sort')->orderBy('id');
    }

    /** @return BelongsTo<ProductTemplateField, $this> */
    public function codeField(): BelongsTo
    {
        return $this->belongsTo(ProductTemplateField::class, 'code_field_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
