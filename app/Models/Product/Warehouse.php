<?php

namespace App\Models\Product;

use App\Models\BaseModel;
use App\Models\Inventory;
use Database\Factories\Product\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Warehouse extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): WarehouseFactory
    {
        return WarehouseFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'name',
    ];

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
