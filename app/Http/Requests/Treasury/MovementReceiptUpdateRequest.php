<?php

namespace App\Http\Requests\Treasury;

use App\Models\Treasury\PaymentReceipt;
use App\StaticClasses\MovementPartyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Corrige después el comprobante (nro. y/o imagen) de un movimiento por banco.
 * Clave ausente = sin cambios; null = se borra.
 */
class MovementReceiptUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // El chequeo de banco lo hace el controlador sobre el movimiento, con su propio mensaje.
        return [
            'receipt_number' => ['sometimes', ...PaymentReceipt::numberRules(MovementPartyRegistry::BANK_ACCOUNT)],
            'receipt_id' => ['sometimes', ...PaymentReceipt::idRules(MovementPartyRegistry::BANK_ACCOUNT)],
        ];
    }
}
