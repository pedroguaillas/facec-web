<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    protected $fillable = [
        'emision_point_id', 'serie',
        'authorization', 'state',
    ];
}
