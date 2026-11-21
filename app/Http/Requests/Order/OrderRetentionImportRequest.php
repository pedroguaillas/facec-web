<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Importación del XML de la retención que el cliente emitió sobre una factura.
 */
class OrderRetentionImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'xml' => ['required', 'file', 'extensions:xml', 'max:1024'],
        ];
    }
}
