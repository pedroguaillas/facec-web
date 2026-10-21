<?php

namespace App\Http\Requests\Inventory;

use App\Rules\BranchStockProductRule;
use App\Rules\BranchWarehouseRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class TransferStoreRequest extends FormRequest
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
            'from_warehouse_id' => ['required', 'integer', new BranchWarehouseRule],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', new BranchWarehouseRule],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', new BranchStockProductRule],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
