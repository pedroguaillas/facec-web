<?php

namespace App\Models;

use App\Models\Order\Order;
use App\Models\ReferralGuide\ReferralGuide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends BaseModel
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
        'rent_retention',
        'iva_retention',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function referralGuides()
    {
        return $this->hasMany(ReferralGuide::class);
    }
}
