<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\BranchStoreRequest;
use App\Http\Requests\Branch\BranchUpdateRequest;
use App\Models\Branch;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::where('company_id', Auth::user()->company->id)
            ->get(['id', 'company_id', 'store', 'address', 'name', 'type']);

        return response()->json([
            'branches' => $branches,
        ], 200);
    }

    public function store(BranchStoreRequest $request, BranchService $service): JsonResponse
    {
        $branch = $service->create($request->validated());

        if ($request->boolean('cf')) {
            $service->createFinalConsumer($branch);
        }

        return response()->json([
            'message' => 'Establecimiento creado con éxito.',
            'branch' => $branch,
        ], 201);
    }

    public function update(BranchUpdateRequest $request, Branch $branch, BranchService $service): JsonResponse
    {
        $branch = $service->update($branch, $request->validated());

        return response()->json([
            'message' => 'Establecimiento actualizado con éxito.',
            'branch' => $branch,
        ], 200);
    }
}
