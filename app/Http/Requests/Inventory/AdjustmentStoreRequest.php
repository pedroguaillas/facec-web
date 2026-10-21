<?php

namespace App\Http\Requests\Inventory;

use App\Rules\BranchStockProductRule;
use App\Rules\BranchWarehouseRule;
use App\StaticClasses\InventoryMovementTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdjustmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * `unit_cost` solo se usa en ingresos; sin él entra al costo promedio vigente.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', new BranchWarehouseRule],
            'direction' => ['required', Rule::in([InventoryMovementTypes::IN, InventoryMovementTypes::OUT])],
            'date' => ['required', 'date'],
            'note' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', new BranchStockProductRule],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
