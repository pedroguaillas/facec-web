<?php

namespace App\Http\Requests\Treasury;

use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Movimiento suelto (sin contraparte interna) sobre una cuenta bancaria o sesión de caja. */
class MovementOtherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $sourceTable = $this->input('source_type') === MovementPartyRegistry::BANK_ACCOUNT
            ? 'bank_accounts'
            : 'cash_register_sessions';

        return [
            'source_type' => ['required', Rule::in([MovementPartyRegistry::BANK_ACCOUNT, MovementPartyRegistry::CASH_REGISTER_SESSION])],
            'source_id' => ['required', 'integer', Rule::exists($sourceTable, 'id')],
            'type' => ['required', Rule::in(MovementTypes::ALL)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['sometimes', 'date'],
            'payment_method' => ['sometimes', Rule::in(PaymentMethods::ALL)],
            'receipt_number' => ['sometimes', 'nullable', 'string', 'max:49'],
            'description' => ['sometimes', 'nullable', 'string'],
            'receipt' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
