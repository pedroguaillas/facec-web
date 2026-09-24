<?php

namespace App\Http\Requests\Bank;

use App\Rules\UniqueBranchScoped;
use App\StaticClasses\BankAccountTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BankAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $bankAccountId = $this->route('bankAccount')->id;

        return [
            'bank_id' => ['required', 'integer', Rule::exists('banks', 'id')],
            'account_number' => ['required', 'string', 'max:50', new UniqueBranchScoped('bank_accounts', 'account_number', $bankAccountId)],
            'type' => ['required', Rule::in(BankAccountTypes::ALL)],
        ];
    }
}
