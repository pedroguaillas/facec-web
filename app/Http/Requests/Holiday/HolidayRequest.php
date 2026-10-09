<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del feriado es obligatorio.',
            'starts_at.required' => 'La fecha de inicio es obligatoria.',
            'ends_at.required' => 'La fecha de fin es obligatoria.',
            'ends_at.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la de inicio.',
        ];
    }
}
