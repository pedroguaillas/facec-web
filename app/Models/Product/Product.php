<?php

namespace App\Models\Product;

use App\Models\BaseModel;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Order\OrderItem;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Models\Shop\ShopItem;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Product extends BaseModel
{
    use HasFactory, SoftDeletes;

    public const TYPE_PRODUCT = 1;

    public const TYPE_SERVICE = 2;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'category_id',
        'parent_product_id',
        // Plantilla de características (solo en el producto modelo/padre) — ver docs/caracteristicas-producto.md
        'product_template_id',
        'code',
        'type_product',
        'name',
        'unity_id',
        'price1',
        'price2',
        'price3',
        'iva',
        'ice',
        'irbpnr',
        'entry_account_id',
        'active_account_id',
        'inventory_account_id',
        'stock',
        'min_stock',
        // IVA diferenciado (8%) en feriados — ver DifferentiatedIvaService
        'differentiated_iva',
        // Obligatorio productos con el IVA 5%
        'aux_cod',
    ];

    protected $casts = [
        'price1' => 'float',
        'stock' => 'float',
        'min_stock' => 'float',
        'differentiated_iva' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unity()
    {
        return $this->belongsTo(Unity::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /** @return HasMany<ProductPresentation, $this> */
    public function presentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function variants()
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

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

    /**
     * Características efectivas: las del modelo (padre) + las propias de la
     * unidad, solo de campos activos, en el orden de la plantilla.
     *
     * @return Collection<int, array{label: string, value: string, in_sri: bool, sort: int}>
     */
    public function characteristics(): Collection
    {
        $this->loadMissing(['details.field', 'parentProduct.details.field']);

        /** @var Collection<int, ProductDetail> $details */
        $details = $this->details->toBase()->concat($this->parentProduct->details ?? []);

        return $details
            ->filter(fn (ProductDetail $detail) => $detail->field->active)
            ->sortBy(fn (ProductDetail $detail) => sprintf('%05d-%010d', $detail->field->sort, $detail->field->id))
            ->map(fn (ProductDetail $detail) => [
                'label' => $detail->field->label,
                'value' => $detail->value,
                'in_sri' => $detail->field->in_sri,
                'sort' => $detail->field->sort,
            ])
            ->values();
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shopItems()
    {
        return $this->hasMany(ShopItem::class);
    }

    public function referralGuideItems()
    {
        return $this->hasMany(ReferralGuideItem::class);
    }

    public function scopeOfType(Builder $query, int $typeProduct): Builder
    {
        return $query->where('products.type_product', $typeProduct);
    }

    /**
     * Solo los productos (no servicios) manejan inventario.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeStockable(Builder $query): Builder
    {
        return $query->where('products.type_product', self::TYPE_PRODUCT);
    }

    public function isStockable(): bool
    {
        return (int) $this->type_product === self::TYPE_PRODUCT;
    }

    /**
     * Un servicio nunca tiene stock: se limpia en cualquier alta/edición.
     */
    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (! $product->isStockable()) {
                $product->stock = null;
                $product->min_stock = null;
            }
        });
    }
}
