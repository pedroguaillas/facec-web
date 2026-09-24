<?php

namespace App\Http\Requests\CashRegister;

use App\StaticClasses\CashRegisterTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CashRegisterStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $branch = Auth::user()->company?->branches()->orderBy('created_at')->first();

        return [
            'name' => ['required', 'string', 'max:100'],
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'type' => [
                'required',
                Rule::in(CashRegisterTypes::ALL),
                Rule::when(
                    $this->input('type') === CashRegisterTypes::GENERAL,
                    [Rule::unique('cash_registers', 'type')->where('branch_id', $branch?->id)],
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.unique' => 'Esta sucursal ya tiene una caja general.',
        ];
    }
}
