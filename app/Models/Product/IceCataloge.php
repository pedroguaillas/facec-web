<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class IceCataloge extends Model
{
    protected $fillable = [
        'code', 'description',
    ];
}
