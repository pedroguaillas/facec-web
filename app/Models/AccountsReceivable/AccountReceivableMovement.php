<?php

namespace App\Models\AccountsReceivable;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Order\Order;
use Database\Factories\AccountsReceivable\AccountReceivableMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivableMovement extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): AccountReceivableMovementFactory
    {
        return AccountReceivableMovementFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'customer_id',
        'order_id',
        'type',
        'debe',
        'haber',
        'previous_balance',
        'new_balance',
        'description',
        'date',
    ];

    protected $casts = [
        'debe' => 'float',
        'haber' => 'float',
        'previous_balance' => 'float',
        'new_balance' => 'float',
        'date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
