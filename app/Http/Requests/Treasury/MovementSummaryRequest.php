<?php

namespace App\Http\Requests\Treasury;

use App\StaticClasses\MovementPartyRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Período del resumen de movimientos; sin fechas, el mes en curso. */
class MovementSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_type' => ['sometimes', 'required_with:party_id', Rule::in(array_keys(MovementPartyRegistry::TYPES))],
            'party_id' => ['sometimes', 'required_with:party_type', 'integer'],
        ];
    }
}
