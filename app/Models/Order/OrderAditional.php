<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class OrderAditional extends Model
{
    protected $fillable = [
        'order_id', 'name', 'description',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
