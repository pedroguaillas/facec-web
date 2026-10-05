<?php

namespace App\Models;

use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $casts = [
        'quantity' => 'float',
        'price' => 'float',
        'previous_balance' => 'float',
        'new_balance' => 'float',
        // 'date' => 'date',
    ];

    protected $fillable = [
        'product_id', 'warehouse_id', 'model_id', 'type', 'quantity',
        'price', 'previous_balance', 'new_balance', 'date', 'code_provider',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
