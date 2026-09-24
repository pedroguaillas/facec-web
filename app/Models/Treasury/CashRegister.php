<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use App\Models\User;
use App\StaticClasses\CashRegisterStatuses;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashRegister extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'responsible_id',
        'type',
        'status',
    ];

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(CashRegisterSession::class)->where('status', CashRegisterStatuses::OPEN);
    }
}
