<?php

namespace App\Http\Requests\Treasury;

use App\Models\Treasury\Movement;
use App\StaticClasses\MovementCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Clasifica después un movimiento suelto (OTRO) desde el listado. */
class MovementCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        /** @var Movement $movement */
        $movement = $this->route('movement');

        return [
            'category' => ['present', 'nullable', Rule::in(MovementCategories::forType($movement->type))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'La categoría no corresponde al tipo de movimiento.',
        ];
    }
}
