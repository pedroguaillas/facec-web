<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    protected $fillable = [
        'company_id',
        'plan',
        'started_at',
        'ended_at',
        'amount',
    ];

    protected $casts = [
        'started_at' => 'date:Y-m-d',
        'ended_at' => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
