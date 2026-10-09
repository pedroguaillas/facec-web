<?php

namespace App\Http\Controllers\Holiday;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\HolidayRequest;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;

class AdminHolidayController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Holiday::orderByDesc('starts_at')->paginate(15)
        );
    }

    public function store(HolidayRequest $request): JsonResponse
    {
        return response()->json(Holiday::create($request->validated()), 201);
    }

    public function update(HolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($request->validated());

        return response()->json($holiday);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['succes' => true]);
    }
}
