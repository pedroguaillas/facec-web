<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

class BranchLogoDestroyRequest extends FormRequest
{
    /**
     * Misma regla que BranchUpdateRequest: admin o dueño del establecimiento.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isAdmin() || $this->route('branch')->company_id === $user->company->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
