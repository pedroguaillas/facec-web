<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Feriado nacional con IVA diferenciado (8%). Global: no pertenece a ninguna
 * empresa ni sucursal.
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'date:Y-m-d',
        'ends_at' => 'date:Y-m-d',
    ];

    /**
     * Feriados que incluyen la fecha dada (ambos extremos inclusive).
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeCovering(Builder $query, CarbonInterface|string $date): void
    {
        $query->whereDate('starts_at', '<=', $date)->whereDate('ends_at', '>=', $date);
    }
}
