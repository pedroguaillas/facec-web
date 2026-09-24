<?php

namespace App\Http\Requests\CashRegister;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CashRegisterCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'denominations' => ['sometimes', 'array'],
            'denominations.*.denomination_id' => ['required_with:denominations', 'integer', Rule::exists('denominations', 'id')],
            'denominations.*.quantity' => ['required_with:denominations', 'integer', 'min:0'],
        ];
    }
}
