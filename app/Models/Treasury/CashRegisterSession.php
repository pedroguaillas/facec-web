<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use App\Models\User;
use App\StaticClasses\DenominationMoments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'cash_register_id',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
        'initial_balance',
        'current_balance',
        'final_balance',
        'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'initial_balance' => 'float',
        'current_balance' => 'float',
        'final_balance' => 'float',
    ];

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function denominations(): HasMany
    {
        return $this->hasMany(CashRegisterSessionDenomination::class);
    }

    public function openingDenominations(): HasMany
    {
        return $this->denominations()->where('moment', DenominationMoments::OPENING);
    }

    public function closingDenominations(): HasMany
    {
        return $this->denominations()->where('moment', DenominationMoments::CLOSING);
    }
}
