<?php

namespace App\Models\Shop;

use App\Models\BaseModel;
use App\Models\Inventory;
use App\Models\Provider;
use App\Models\Treasury\Movement;
use App\Models\VoucherType;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'voucher_type_id',
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
        // Clasificación de pago (App\StaticClasses\PaymentTypes)
        'payment_type',
        'due_date',
        'movement_id',
    ];

    protected $casts = [
        'voucher_type_id' => 'integer',
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
        'due_date' => 'date',
    ];

    public function shopitems()
    {
        return $this->hasMany(ShopItem::class);
    }

    public function inventories(): MorphMany
    {
        return $this->morphMany(Inventory::class, 'model');
    }

    public function shopretentionitems()
    {
        return $this->hasMany(ShopRetentionItem::class);
    }

    /**
     * Total retenido al proveedor: no se le paga, así que descuenta del saldo.
     */
    public function retentionTotal(): float
    {
        // `retention_sum` viene de withSum() (null si no hay retención).
        if (array_key_exists('retention_sum', $this->getAttributes())) {
            return (float) $this->getAttribute('retention_sum');
        }

        return (float) $this->shopretentionitems()->sum('value');
    }

    /**
     * Saldo pendiente de pago al proveedor: total - retención - pagado.
     */
    public function pendingBalance(): float
    {
        return round((float) $this->total - $this->retentionTotal() - (float) $this->paid, 2);
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }
}
