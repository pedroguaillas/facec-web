<?php

namespace App\Models;

use App\Models\Shop\Shop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'state',
        'type_identification',
        'identication',
        'name',
        'address',
        'phone',
        'email',
        'accounting',
        'discount',
    ];

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }
}
