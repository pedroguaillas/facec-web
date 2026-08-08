<?php

namespace App\Models;

use App\Models\ReferralGuide\ReferralGuide;
use Illuminate\Database\Eloquent\SoftDeletes;

class Carrier extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'type_identification', 'identication',
        'name', 'email', 'license_plate',
    ];

    public function referralGuides()
    {
        return $this->hasMany(ReferralGuide::class);
    }
}
