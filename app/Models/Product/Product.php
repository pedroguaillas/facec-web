<?php

namespace App\Models\Product;

use App\Models\BaseModel;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Order\OrderItem;
use App\Models\ReferralGuide\ReferralGuideItem;
use App\Models\Shop\ShopItem;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'category_id',
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
        'tourism',
        // Obligatorio productos con el IVA 5%
        'aux_cod',
    ];

    protected $casts = [
        'price1' => 'float',
        'tourism' => 'boolean',
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
}
