<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends BaseModel
{
    use HasFactory;

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
        'rent_retention',
        'iva_retention',
    ];
}
