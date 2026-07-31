<?php

namespace App\Http\Requests\Carrier;

use App\Rules\UniqueBranchScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CarrierStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'type_identification' => ['required', 'string'],
            'identication' => ['required', 'string', new UniqueBranchScoped('carriers', 'identication')],
            'name' => ['required', 'string', 'max:300'],
            'email' => ['nullable', 'email', 'max:300'],
            'license_plate' => ['nullable', 'string', 'max:20'],
        ];
    }
}
