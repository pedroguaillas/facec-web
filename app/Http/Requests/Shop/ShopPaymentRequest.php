<?php

namespace App\Http\Requests\Shop;

use App\Models\Treasury\PaymentReceipt;
use App\StaticClasses\MovementPartyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ShopPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'source_type' => ['required', Rule::in([MovementPartyRegistry::BANK_ACCOUNT, MovementPartyRegistry::CASH_REGISTER_SESSION])],
            'source_id' => ['required', 'integer', Rule::exists($sourceTable, 'id')],
            'date' => ['sometimes', 'date'],
            'receipt_id' => PaymentReceipt::idRules($this->input('source_type')),
            'receipt_number' => PaymentReceipt::numberRules($this->input('source_type')),
        ];
    }
}
