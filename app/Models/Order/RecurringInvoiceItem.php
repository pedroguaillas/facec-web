<?php

namespace App\Models\Order;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringInvoiceItem extends Model
{
    protected $fillable = [
        'recurring_invoice_id',
        'product_id',
        'code',
        'description',
        'quantity',
        'price',
        'discount',
        'iva',
    ];

    protected $casts = [
        'quantity' => 'float',
        'price' => 'float',
        'discount' => 'float',
        'iva' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
