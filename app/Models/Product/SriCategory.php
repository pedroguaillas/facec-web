<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

class SriCategory extends Model
{
    protected $fillable = [
        'code', 'type', 'description',
    ];
}
