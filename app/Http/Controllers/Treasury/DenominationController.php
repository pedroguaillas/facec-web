<?php

namespace App\Http\Controllers\Treasury;

use App\Http\Controllers\Controller;
use App\Http\Resources\DenominationResources;
use App\Models\Denomination;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DenominationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DenominationResources::collection(
            Denomination::orderBy('type')->orderBy('value')->get(),
        );
    }
}
