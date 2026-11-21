<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRetentionItem extends Model
{
    protected $fillable = [
        'code', 'tax_code',
        'base', 'porcentage',
        'value', 'order_id',
    ];

    protected $casts = [
        'code' => 'integer',
        'base' => 'float',
        'porcentage' => 'float',
        'value' => 'float',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
