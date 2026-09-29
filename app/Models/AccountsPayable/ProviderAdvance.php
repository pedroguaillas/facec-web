<?php

namespace App\Models\AccountsPayable;

use App\Models\BaseModel;
use App\Models\Provider;
use App\Models\Treasury\Movement;
use Database\Factories\AccountsPayable\ProviderAdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAdvance extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): ProviderAdvanceFactory
    {
        return ProviderAdvanceFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'provider_id',
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

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }
}
