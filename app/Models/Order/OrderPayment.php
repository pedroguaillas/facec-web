<?php

namespace App\Models\Order;

use App\Models\MethodOfPayment;
use App\Models\Treasury\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forma de pago de una venta (un <pago> del XML del SRI).
 */
class OrderPayment extends Model
{
    protected $fillable = [
        'order_id',
        'pay_method',
        'payment_type',
        'amount',
        'movement_id',
    ];

    protected $casts = [
        'pay_method' => 'integer',
        'amount' => 'float',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Movement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    /** @return BelongsTo<MethodOfPayment, $this> */
    public function methodOfPayment(): BelongsTo
    {
        return $this->belongsTo(MethodOfPayment::class, 'pay_method', 'code');
    }
}
