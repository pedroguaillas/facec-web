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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function parentProduct()
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function variants()
    {
        return $this->hasMany(self::class, 'parent_product_id');
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
