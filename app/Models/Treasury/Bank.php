<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
    ];

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
