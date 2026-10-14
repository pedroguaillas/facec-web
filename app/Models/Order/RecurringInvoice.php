<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\User;
use Database\Factories\Order\RecurringInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de factura que se emite sola cada período (ver
 * RecurringInvoiceService y el comando `invoices:recurring`).
 */
class RecurringInvoice extends BaseModel
{
    /** @use HasFactory<RecurringInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'customer_id',
        'emision_point_id',
        'user_id',
        'name',
        'frequency',
        'start_at',
        'end_date',
        'max_occurrences',
        'next_run_at',
        'status',
        'auto_send',
        'payment_type',
        'due_days',
        'pay_method',
        'source_type',
        'source_id',
        'description',
        'aditionals',
        'emitted_count',
        'last_run_at',
        'last_error',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_date' => 'date',
        'next_run_at' => 'datetime',
        'auto_send' => 'boolean',
        'aditionals' => 'array',
        'max_occurrences' => 'integer',
        'due_days' => 'integer',
        'pay_method' => 'integer',
        'emitted_count' => 'integer',
        'last_run_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<EmisionPoint, $this>
     */
    public function emisionPoint(): BelongsTo
    {
        return $this->belongsTo(EmisionPoint::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RecurringInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceItem::class);
    }

    /**
     * @return HasMany<RecurringInvoiceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class);
    }
}
