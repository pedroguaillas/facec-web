<?php

namespace App\Models\ReferralGuide;

use App\Models\BaseModel;

class ReferralGuide extends BaseModel
{
    protected $fillable = [
        'branch_id', 'customer_id', 'carrier_id',
        'serie', 'address_from', 'address_to',
        'date_start', 'date_end', 'reason_transfer',
        'customs_doc', 'branch_destiny', 'route',

        // Si es de una factura poner estos campos
        'serie_invoice', 'authorization_invoice', 'date_invoice',

        // Electronic
        'state', 'autorized',
        'authorization', 'xml',
        'extra_detail', 'send_mail',
    ];

    public function referralguidetems()
    {
        return $this->hasMany(ReferralGuideItem::class);
    }
}
