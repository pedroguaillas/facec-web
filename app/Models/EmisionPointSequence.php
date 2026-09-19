<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmisionPointSequence extends Model
{
    protected $fillable = [
        'emision_point_id', 'voucher_type_id', 'next_number',
    ];

    protected $casts = [
        'next_number' => 'integer',
    ];

    public function emisionPoint(): BelongsTo
    {
        return $this->belongsTo(EmisionPoint::class);
    }

    public function voucherType(): BelongsTo
    {
        return $this->belongsTo(VoucherType::class);
    }
}
