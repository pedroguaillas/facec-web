<?php

namespace App\Http\Requests\Treasury;

use App\StaticClasses\PaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Retiro: banco → caja. */
class MovementWithdrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')],
            'cash_register_session_id' => ['required', 'integer', Rule::exists('cash_register_sessions', 'id')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['sometimes', 'date'],
            'payment_method' => ['sometimes', Rule::in(PaymentMethods::ALL)],
            'receipt_number' => ['sometimes', 'nullable', 'string', 'max:49'],
            'description' => ['sometimes', 'nullable', 'string'],
            'receipt' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
