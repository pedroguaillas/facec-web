<?php

namespace App\Models\AccountsReceivable;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Treasury\Movement;
use Database\Factories\AccountsReceivable\CustomerAdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAdvance extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): CustomerAdvanceFactory
    {
        return CustomerAdvanceFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'customer_id',
        'movement_id',
        'amount',
        'applied_amount',
        'date',
    ];

    protected $casts = [
        'amount' => 'float',
        'applied_amount' => 'float',
        'date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }
}
