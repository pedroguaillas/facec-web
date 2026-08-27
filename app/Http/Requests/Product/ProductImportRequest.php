<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio',
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls)',
            'file.max' => 'El archivo no debe superar 5MB',
        ];
    }
}
