<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmisionPoint extends Model
{
    use HasFactory;

    protected $casts = [
        'lote' => 'integer',
    ];

    protected $fillable = [
        'branch_id', 'point', 'enabled',
        'invoice', 'creditnote', 'retention',
        'referralguide', 'settlementonpurchase',
        'lot', 'recognition',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
