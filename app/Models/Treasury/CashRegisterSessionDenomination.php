<?php

namespace App\Models\Treasury;

use App\Models\Denomination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterSessionDenomination extends Model
{
    protected $fillable = [
        'cash_register_session_id',
        'denomination_id',
        'quantity',
        'moment',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }
}
