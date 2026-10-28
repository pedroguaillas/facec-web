<?php

namespace App\Models\Treasury;

use App\Models\BaseModel;
use App\StaticClasses\MovementPartyRegistry;
use Database\Factories\Treasury\PaymentReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Comprobante subido antes de registrar un cobro/pago por banco. Ver
 * docs/planes.md §5.2 — flujo en dos pasos (POST payment-receipts → `receipt_id`).
 */
class PaymentReceipt extends BaseModel
{
    /** @use HasFactory<PaymentReceiptFactory> */
    use HasFactory, Prunable;

    /** Horas que un comprobante puede quedar sin vincular antes de purgarse. */
    const ORPHAN_HOURS = 24;

    protected $fillable = [
        'branch_id',
        'path',
        'mime_type',
        'movement_id',
        'user_id',
    ];

    /**
     * Reglas del `receipt_id` opcional de un cobro/pago: solo con banco, de
     * la empresa (sucursal más antigua, como el BranchScope) y aún sin vincular.
     *
     * @return array<int, mixed>
     */
    public static function idRules(?string $sourceType): array
    {
        $branchId = Auth::user()->company->branches()->orderBy('created_at')->value('id');

        return [
            'nullable',
            'integer',
            Rule::prohibitedIf($sourceType !== MovementPartyRegistry::BANK_ACCOUNT),
            Rule::exists('payment_receipts', 'id')->where('branch_id', $branchId)->whereNull('movement_id'),
        ];
    }

    /**
     * Reglas del `receipt_number` opcional (nro. de transferencia/depósito):
     * solo con banco, mismo largo que en movimientos manuales.
     *
     * @return array<int, mixed>
     */
    public static function numberRules(?string $sourceType): array
    {
        return [
            'nullable',
            'string',
            'max:49',
            Rule::prohibitedIf($sourceType !== MovementPartyRegistry::BANK_ACCOUNT),
        ];
    }

    /**
     * @return BelongsTo<Movement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class);
    }

    /**
     * Sin usuario autenticado (scheduler) el BranchScope no aplica, así que se
     * quita explícitamente: la purga es de todas las empresas.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()
            ->whereNull('movement_id')
            ->where('created_at', '<=', now()->subHours(self::ORPHAN_HOURS));
    }

    protected function pruning(): void
    {
        Storage::delete($this->path);
    }
}
