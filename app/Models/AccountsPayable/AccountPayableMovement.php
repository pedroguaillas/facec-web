<?php

namespace App\Models\AccountsPayable;

use App\Models\BaseModel;
use App\Models\Provider;
use App\Models\Shop\Shop;
use Database\Factories\AccountsPayable\AccountPayableMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayableMovement extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): AccountPayableMovementFactory
    {
        return AccountPayableMovementFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'provider_id',
        'shop_id',
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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
