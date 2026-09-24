<?php

namespace App\Http\Controllers\AccountsPayable;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountPayableMovementResource;
use App\Models\AccountsPayable\AccountPayableMovement;
use App\Models\Provider;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountPayableController extends Controller
{
    public function index(Provider $provider): AnonymousResourceCollection
    {
        $movements = AccountPayableMovement::where('provider_id', $provider->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15);

        return AccountPayableMovementResource::collection($movements);
    }
}
