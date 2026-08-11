<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmisionPoint\EmisionPointStoreRequest;
use App\Http\Requests\EmisionPoint\EmisionPointUpdateRequest;
use App\Models\Branch;
use App\Models\EmisionPoint;
use App\Services\EmisionPointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmisionPointController extends Controller
{
    public function index(Branch $branch): JsonResponse
    {
        abort_unless($branch->company_id === Auth::user()->company->id, 403);

        $points = $branch->emisionPoints()->get([
            'id', 'point', 'invoice', 'creditnote', 'retention',
            'referralguide', 'settlementonpurchase', 'recognition',
        ]);

        return response()->json($points, 200);
    }

    public function store(EmisionPointStoreRequest $request, EmisionPointService $service): JsonResponse
    {
        $emisionPoint = $service->create($request->validated());

        return response()->json($emisionPoint, 201);
    }

    public function update(EmisionPointUpdateRequest $request, EmisionPoint $emisionPoint): JsonResponse
    {
        $emisionPoint->update($request->validated());

        return response()->json($emisionPoint, 200);
    }
}
