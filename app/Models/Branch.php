<?php

namespace App\Models;

use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\ReferralGuide\ReferralGuide;
use App\Models\Shop\Shop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'store', 'address', 'name', 'type', 'logo_dir'];

    public function emisionPoints()
    {
        return $this->hasMany(EmisionPoint::class, 'branch_id');
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function providers()
    {
        return $this->hasMany(Provider::class);
    }

    public function carriers()
    {
        return $this->hasMany(Carrier::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }

    public function referralguides()
    {
        return $this->hasMany(ReferralGuide::class);
    }

    public function unities()
    {
        return $this->hasMany(Unity::class);
    }

    /**
     * Logo que va en los PDFs del establecimiento: el propio o, si no tiene,
     * el de la empresa; `default.png` si ninguno lo cargó.
     */
    public function logoFile(): string
    {
        return $this->logo_dir ?: $this->company->logo_dir ?: 'default.png';
    }

    /**
     * Establecimiento que emitió un comprobante, según el código `estab` de
     * su serie ("002-001-000000123" → store 2). Las ventas se guardan
     * siempre en la sucursal más antigua (BranchScope), así que la serie es
     * la única fuente del establecimiento real; si no existe, la más antigua.
     */
    public static function forSerie(Company $company, string $serie): ?self
    {
        return self::where(['company_id' => $company->id, 'store' => (int) substr($serie, 0, 3)])->first()
            ?? self::where('company_id', $company->id)->orderBy('created_at')->first();
    }

    // public function chartaccounts()
    // {
    //     return $this->hasMany(ChartAccount::class);
    // }
}
