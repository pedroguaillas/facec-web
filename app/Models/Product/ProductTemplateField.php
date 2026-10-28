<?php

namespace App\Models\Product;

use Database\Factories\Product\ProductTemplateFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductTemplateField extends Model
{
    /** @use HasFactory<ProductTemplateFieldFactory> */
    use HasFactory;

    const TYPE_TEXT = 'text';

    const TYPE_NUMBER = 'number';

    const TYPE_YEAR = 'year';

    const TYPES = [self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_YEAR];

    /** Lo comparte el producto padre (Marca, Año, Modelo). */
    const LEVEL_MODEL = 'modelo';

    /** Propio de cada unidad física / variante (Chasis, Motor, RAMV). */
    const LEVEL_UNIT = 'unidad';

    const LEVELS = [self::LEVEL_MODEL, self::LEVEL_UNIT];

    protected static function newFactory(): ProductTemplateFieldFactory
    {
        return ProductTemplateFieldFactory::new();
    }

    protected $fillable = [
        'product_template_id',
        'key',
        'label',
        'type',
        'level',
        'required',
        'unique',
        'in_sri',
        'sort',
        'active',
    ];

    protected $casts = [
        'required' => 'boolean',
        'unique' => 'boolean',
        'in_sri' => 'boolean',
        'sort' => 'integer',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<ProductTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductTemplate::class, 'product_template_id');
    }

    /** @return HasMany<ProductDetail, $this> */
    public function details(): HasMany
    {
        return $this->hasMany(ProductDetail::class);
    }
}
