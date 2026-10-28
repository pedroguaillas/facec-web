<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use App\Models\User;
use App\StaticClasses\MovementPartyRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Movement extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'date',
        'type',
        'transaction_type',
        'category',
        'source_type',
        'source_id',
        'destination_type',
        'destination_id',
        'origin_type',
        'origin_id',
        'amount',
        'previous_balance',
        'new_balance',
        'status',
        'receipt_number',
        'payment_method',
        'description',
        'receipt_path',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
        'previous_balance' => 'float',
        'new_balance' => 'float',
    ];

    /** Solo los movimientos con cuenta bancaria (origen o destino) llevan comprobante. */
    public function involvesBankAccount(): bool
    {
        return in_array(MovementPartyRegistry::BANK_ACCOUNT, [$this->source_type, $this->destination_type], true);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Venta o compra que generó el movimiento (alias `order`/`shop` del morph map).
     */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
