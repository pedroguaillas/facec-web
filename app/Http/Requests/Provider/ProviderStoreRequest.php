<?php

namespace App\Http\Requests\Provider;

use App\Rules\UniqueBranchScoped;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProviderStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type_identification' => 'required|string|in:cédula,ruc,pasaporte',
            'identication' => [
                'required',
                'string',
                new UniqueBranchScoped('providers', 'identication'),
            ],
            'name' => 'required|min:3|max:250',
            'address' => 'required|min:3|max:250',
            'phone' => 'nullable',
            'email' => 'nullable|email',
        ];
    }
}
