<?php

namespace App\Http\Controllers\AccountsReceivable;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountReceivableMovementResource;
use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountReceivableController extends Controller
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        $movements = AccountReceivableMovement::where('customer_id', $customer->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return AccountReceivableMovementResource::collection($movements);
    }
}
