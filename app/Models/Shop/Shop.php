<?php

namespace App\Models\Shop;

use App\Models\BaseModel;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shop extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): ShopFactory
    {
        return ShopFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'date',
        'description',
        'sub_total',
        'serie',
        'provider_id',
        'doc_realeted',
        'expiration_days',
        'no_iva',
        'base0',
        'base12',
        'iva',
        'discount',
        'ice',
        'total',
        'voucher_type',
        'paid',

        // Electrónico
        'state',
        'autorized',
        'authorization',
        'iva_retention',
        'rent_retention',
        'xml',
        'extra_detail',
        'send_mail_set_purchase',

        // Retención
        'serie_retencion',
        'date_retention',
        'send_mail_retention',

        // Retención electrónica
        'state_retencion',
        'autorized_retention',
        'authorization_retention',
        'xml_retention',
        'extra_detail_retention',
        // Actualizacion del IVA
        'base5',
        'base15',
        'iva5',
        'iva15',
    ];

    protected $casts = [
        'no_iva' => 'float',
        'base0' => 'float',
        'base5' => 'float',
        'base12' => 'float',
        'base15' => 'float',
        'iva5' => 'float',
        'iva' => 'float',
        'iva15' => 'float',
        'discount' => 'float',
        'ice' => 'float',
        'sub_total' => 'float',
        'total' => 'float',
    ];

    public function shopitems()
    {
        return $this->hasMany(ShopItem::class);
    }

    public function shopretentionitems()
    {
        return $this->hasMany(ShopRetentionItem::class);
    }
}
