<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->route('company')?->id ?? $this->user()->company?->id;

        return [
            'ruc' => ['sometimes', 'string', 'digits:13', Rule::unique('companies')->ignore($companyId)],
            'company' => ['sometimes', 'string', 'max:300'],
            'economic_activity' => ['sometimes', 'string', 'max:300'],
            'accounting' => ['sometimes'],
            'micro_business' => ['sometimes', 'boolean'],
            'retention_agent' => ['sometimes', 'nullable', 'integer'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:15'],
            'enviroment_type' => ['sometimes', 'integer', 'in:1,2'],
            'active' => ['sometimes', 'boolean'],
            'active_voucher' => ['sometimes', 'boolean'],
            'decimal' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'sign_valid_from' => ['sometimes', 'nullable', 'date'],
            'sign_valid_to' => ['sometimes', 'nullable', 'date'],
            'logo' => ['sometimes', 'file', 'image', 'max:2048'],
            'cert' => ['sometimes', 'file', 'mimes:p12,pfx', 'max:2048'],
            'pass_cert' => ['required_with:cert', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.digits' => 'El RUC debe tener 13 dígitos.',
            'ruc.unique' => 'Este RUC ya pertenece a otra empresa.',
            'decimal.max' => 'El máximo de decimales permitido es 6.',
        ];
    }
}
