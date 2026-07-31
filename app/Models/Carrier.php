<?php

namespace App\Models;

class Carrier extends BaseModel
{
    protected $fillable = [
        'branch_id', 'type_identification', 'identication',
        'name', 'email', 'license_plate',
    ];
}
