<?php

namespace App\Models\Product;

use App\Models\BaseModel;
use App\Models\Provider;
use Database\Factories\Product\ProviderProductCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderProductCode extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): ProviderProductCodeFactory
    {
        return ProviderProductCodeFactory::new();
    }

    protected $fillable = [
        'branch_id',
        'provider_id',
        'product_id',
        'code',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
