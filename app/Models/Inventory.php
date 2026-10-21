<?php

namespace App\Models;

use App\Models\Product\Product;
use App\Models\Product\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fila del kardex. Solo se inserta (nunca se edita ni se borra): las
 * correcciones son movimientos de reversa (`reverses_id`). Ver
 * App\Services\Product\InventoryLedgerService y docs/inventario.md.
 */
class Inventory extends Model
{
    protected $casts = [
        'quantity' => 'float',
        'price' => 'float',
        'unit_cost' => 'float',
        'previous_balance' => 'float',
        'new_balance' => 'float',
        'average_cost' => 'float',
        'balance_value' => 'float',
    ];

    protected $fillable = [
        'product_id', 'warehouse_id', 'model_id', 'model_type', 'type', 'direction',
        'quantity', 'price', 'unit_cost', 'previous_balance', 'new_balance',
        'average_cost', 'balance_value', 'reverses_id', 'document_uuid',
        'user_id', 'note', 'date',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Documento que originó el movimiento (venta o compra).
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Movimiento original que esta fila revierte.
     *
     * @return BelongsTo<Inventory, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * Reversa que anuló esta fila, si existe.
     *
     * @return HasOne<Inventory, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /**
     * Movimientos vigentes: ni revertidos ni reversas en sí.
     *
     * @param  Builder<Inventory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('reverses_id')->whereDoesntHave('reversal');
    }
}
