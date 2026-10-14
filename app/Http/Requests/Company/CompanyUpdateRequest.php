<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CompanyUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $blankable = ['sign_valid_from', 'sign_valid_to', 'pass_cert', 'artisan_number'];

        // Desde FormData los booleanos llegan como "true"/"false".
        $booleans = ['base5', 'base8', 'ice', 'transport', 'repayment', 'printf', 'guia_in_invoice', 'import_in_invoice', 'import_in_invoices'];

        $this->merge(
            collect($this->only($booleans))
                ->map(fn ($value) => is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value : $value)
                ->all()
        );

        $this->merge(
            collect($this->only($blankable))
                ->map(fn ($value) => in_array($value, ['', 'null', 'undefined', 'Invalid Date'], true) ? null : $value)
                ->all()
        );
    }

    public function rules(): array
    {
        $companyId = $this->route('company')?->id ?? $this->user()->company?->id;

        return [
            'ruc' => ['sometimes', 'string', 'digits:13', Rule::unique('companies')->ignore($companyId)],
            'company' => ['sometimes', 'string', 'max:300'],
            'economic_activity' => ['sometimes', 'string', 'max:300'],
            'accounting' => ['sometimes'],
            'rimpe' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1, Company::RIMPE_NEGOCIO_POPULAR])],
            // Confirmación explícita para pasar a Negocio Popular (cambia todos los productos a IVA 0%).
            'confirm_iva_change' => ['sometimes'],
            'retention_agent' => ['sometimes', 'nullable', 'integer'],
            'artisan_number' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/\. ]+$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:15'],
            'enviroment_type' => ['sometimes', 'integer', 'in:1,2'],
            'active' => ['sometimes', 'boolean'],
            'active_voucher' => ['sometimes', 'boolean'],
            'decimal' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'sign_valid_from' => ['nullable', 'date'],
            'sign_valid_to' => ['nullable', 'required_with:cert', 'date', 'after:sign_valid_from'],
            'logo' => ['sometimes', 'file', 'image', 'max:2048'],
            'cert' => ['sometimes', 'file', 'extensions:p12,pfx', 'max:2048'],
            'pass_cert' => ['nullable', 'required_with:cert', 'string', 'max:50'],
            'base5' => ['sometimes', 'boolean'],
            'base8' => ['sometimes', 'boolean'],
            'tourism_from' => ['nullable', 'date'],
            'tourism_to' => ['nullable', 'date', 'after_or_equal:tourism_from'],
            'ice' => ['sometimes', 'boolean'],
            'inventory' => ['sometimes', 'boolean'],
            'pay_method' => ['sometimes', 'integer'],
            'printf' => ['sometimes', 'boolean'],
            'guia_in_invoice' => ['sometimes', 'boolean'],
            'import_in_invoice' => ['sometimes', 'boolean'],
            'import_in_invoices' => ['sometimes', 'boolean'],
            'transport' => ['sometimes', 'boolean'],
            'repayment' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('rimpe')) {
                    return;
                }

                $routeCompany = $this->route('company');
                $company = $routeCompany instanceof Company ? $routeCompany : $this->user()->company;
                $companyService = app(CompanyService::class);

                if (! $company instanceof Company || ! $companyService->becomesNegocioPopular($company, $this->input('rimpe'))) {
                    return;
                }

                if (! $this->boolean('confirm_iva_change')) {
                    $products = $companyService->countProductsOutsideNegocioPopularIva($company);

                    $validator->errors()->add(
                        'confirm_iva_change',
                        "Al pasar a RIMPE Negocio Popular, {$products} producto(s) cambiarán a IVA 0%. Confirme el cambio para continuar."
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.digits' => 'El RUC debe tener 13 dígitos.',
            'ruc.unique' => 'Este RUC ya pertenece a otra empresa.',
            'decimal.max' => 'El máximo de decimales permitido es 6.',
            'rimpe.in' => 'El régimen RIMPE seleccionado no es válido.',
            'artisan_number.regex' => 'El número de calificación artesanal solo admite letras, números, guiones, puntos y barras.',
        ];
    }
}
