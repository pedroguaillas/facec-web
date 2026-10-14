<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Company extends Model
{
    use HasFactory;

    /** Valor de `rimpe` para contribuyentes RIMPE Negocio Popular. */
    const RIMPE_NEGOCIO_POPULAR = 2;

    /**
     * Códigos de IVA permitidos para Negocio Popular: solo tarifa 0%, ni
     * "no objeto" ni "exento" (decisión de negocio, 2026-10-08).
     */
    const NEGOCIO_POPULAR_IVA_CODES = [0];

    /**
     * Nunca serializar la contraseña del certificado de firma electrónica.
     */
    protected $hidden = [
        'pass_cert',
    ];

    protected $fillable = [
        'ruc',
        'company',
        'economic_activity',
        'accounting',
        'retention_agent',
        'phone',
        'active',
        'active_voucher',
        'logo_dir',
        'cert_dir',
        'pass_cert',
        'sign_valid_from',
        'sign_valid_to',
        'enviroment_type',
        'decimal',

        // Ajuste base5 solo para ferreterias
        'base5',

        // Ajuste base8 solo para turistas
        'base8',
        'tourism_from',
        'tourism_to',

        // Ajuste ICE
        'ice',

        // Agregado las 2 columas el 25 de enero del 2022
        'rimpe',
        'inventory',

        // Nro. de calificación artesanal; va como campo adicional en el XML
        'artisan_number',

        // Metodo de pago
        'pay_method',

        // New facec.ec
        'printf',               // Imprimir en impresora termica
        'guia_in_invoice',      // Mostrar guia de remision al crear factura
        'import_in_invoice',    // Importar excel con la lista de productos para crear factura
        'import_in_invoices',    // Importar excel en ventas factura

        // Transport
        'transport',
        'repayment',
    ];

    protected $casts = [
        'accounting' => 'boolean',
        'active' => 'boolean',
        'active_voucher' => 'boolean',
        'base5' => 'boolean',
        'base8' => 'boolean',
        'tourism_from' => 'date',
        'tourism_to' => 'date',
        'inventory' => 'boolean',
        'ice' => 'boolean',
        'rimpe' => 'integer',
        'sign_valid_to' => 'datetime',

        // New facec.ec
        'printf' => 'boolean',
        'guia_in_invoice' => 'boolean',
        'import_in_invoice' => 'boolean',
        'import_in_invoices' => 'boolean',

        // Transport
        'transport' => 'boolean',
        'repayment' => 'boolean',
    ];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Usuario principal de la empresa: el primero vinculado en company_users
     * (el que se crea junto con la empresa en el alta del admin).
     *
     * @return HasOneThrough<User, CompanyUser, $this>
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, CompanyUser::class, 'level_id', 'id', 'id', 'user_id')
            ->select(['users.id', 'users.user', 'users.email'])
            ->without('type')
            ->orderBy('company_users.id');
    }

    public function isNegocioPopular(): bool
    {
        return $this->rimpe === self::RIMPE_NEGOCIO_POPULAR;
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Plan vigente hoy (started_at <= hoy <= ended_at); si hay más de uno, el
     * de started_at más reciente.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany([
            'started_at' => 'max',
            'id' => 'max',
        ], function (Builder $query) {
            $query->whereDate('started_at', '<=', now())
                ->whereDate('ended_at', '>=', now());
        });
    }

    /**
     * Última suscripción de la empresa, vigente o ya vencida (null solo si
     * nunca tuvo plan). La usa el listado admin para mostrar siempre el plan
     * y su vencimiento.
     *
     * @return HasOne<Subscription, $this>
     */
    public function lastSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany([
            'started_at' => 'max',
            'id' => 'max',
        ]);
    }

    /**
     * Plan vigente hoy, o null si no tiene ninguno activo (plan vencido sin
     * renovar, por ejemplo). Siempre consulta la base, no usa la relación cargada.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscription()->first();
    }
}
