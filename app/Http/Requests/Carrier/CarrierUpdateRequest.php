<?php

namespace App\Http\Requests\Carrier;

use App\Rules\UniqueBranchScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CarrierUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        $carrierId = $this->route('carrier')->id;

        return [
            'type_identification' => ['required', 'string'],
            'identication' => ['required', 'string', new UniqueBranchScoped('carriers', 'identication', $carrierId)],
            'name' => ['required', 'string', 'max:300'],
            'email' => ['nullable', 'email', 'max:300'],
            'license_plate' => ['required', 'string', 'max:20'],
        ];
    }
}
