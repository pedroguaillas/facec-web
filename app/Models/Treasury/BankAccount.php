<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'bank_id',
        'account_number',
        'type',
        'balance',
    ];

    protected $casts = [
        'balance' => 'float',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
