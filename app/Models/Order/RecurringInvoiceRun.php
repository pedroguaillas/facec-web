<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una emisión (o intento fallido) de una factura recurrente para un período. */
class RecurringInvoiceRun extends Model
{
    protected $fillable = [
        'recurring_invoice_id',
        'period_date',
        'order_id',
        'status',
        'message',
        'attempts',
    ];

    protected $casts = [
        'period_date' => 'date',
        'attempts' => 'integer',
    ];

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
