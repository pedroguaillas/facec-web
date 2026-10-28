<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order\Repayment\Repayment;
use App\Models\Treasury\Movement;
use App\Models\VoucherType;
use App\StaticClasses\VoucherStates;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends BaseModel
{
    use HasFactory;

    /**
     * Info adicional obligatoria en toda venta, para todas las empresas.
     *
     * @return array{name: string, description: string}
     */
    public static function requiredAditional(): array
    {
        return [
            'name' => 'RUC Proveedor',
            'description' => config('services.order.ruc_proveedor'),
        ];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'date',
        'description',
        'sub_total',
        'serie',
        'customer_id',
        'doc_realeted',
        'expiration_days',
        'no_iva',
        'base0',
        'base12',
        'ice',
        'iva',
        'discount',
        'total',
        'voucher_type_id',
        'paid',
        // Electronic
        'state',
        'autorized',
        'authorization',
        'iva_retention',
        'rent_retention',
        'xml',
        'extra_detail',
        'send_mail',
        // Guia de Remisión
        'guia',
        // Retencion
        'serie_retencion',
        'date_retention',
        'authorization_retention',
        // Metodo de pago
        'pay_method',
        // Placa vehicular (transporte terrestre)
        'plate',
        // Nota de Credito
        'date_order',
        'serie_order',
        'reason',
        // Cambio del % IVA
        'base5',
        'base8',
        'base15',
        'iva5',
        'iva8',
        'iva15',
        // Envio por lotes
        'lot_id',
        // Clasificación de pago (App\StaticClasses\PaymentTypes)
        'payment_type',
        'due_date',
        'movement_id',
        // Proforma de la que se generó esta factura (convert-to-invoice)
        'proforma_id',
    ];

    protected $casts = [
        'no_iva' => 'float',
        'base0' => 'float',
        'base5' => 'float',
        'base8' => 'float',
        'base12' => 'float',
        'base15' => 'float',
        'ice' => 'float',
        'discount' => 'float',
        'iva5' => 'float',
        'iva8' => 'float',
        'iva' => 'float',
        'iva15' => 'float',
        'sub_total' => 'float',
        'total' => 'float',
        'voucher_type_id' => 'integer',
        'send_mail' => 'boolean',
        'due_date' => 'date',
    ];

    /** @return BelongsTo<VoucherType, $this> */
    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class);
    }

    /**
     * Última factura vigente (no anulada) generada desde esta proforma.
     *
     * @return HasOne<Order, $this>
     */
    public function convertedInvoice(): HasOne
    {
        return $this->hasOne(self::class, 'proforma_id')->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where(fn ($state) => $state->whereNull('orders.state')->orWhere('orders.state', '!=', VoucherStates::CANCELED)),
        );
    }

    /**
     * `{id, serie, state}` de la factura vigente de la proforma, o null.
     *
     * @return array{id: int, serie: string, state: string|null}|null
     */
    public function convertedInvoiceSummary(): ?array
    {
        $invoice = $this->convertedInvoice;

        return $invoice ? [
            'id' => $invoice->id,
            'serie' => "{$invoice->voucherType->abbreviation} {$invoice->serie}",
            'state' => $invoice->state,
        ] : null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    public function orderitems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function repayments()
    {
        return $this->hasMany(Repayment::class);
    }

    /** @return HasMany<OrderAditional, $this> */
    public function orderaditionals(): HasMany
    {
        return $this->hasMany(OrderAditional::class);
    }

    public function inventories(): MorphMany
    {
        return $this->morphMany(Inventory::class, 'model');
    }
}
