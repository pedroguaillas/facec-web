<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\BranchLogoDestroyRequest;
use App\Http\Requests\Branch\BranchStoreRequest;
use App\Http\Requests\Branch\BranchUpdateRequest;
use App\Models\Branch;
use App\Services\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::where('company_id', Auth::user()->company->id)
            ->get(['id', 'company_id', 'store', 'address', 'name', 'type', 'logo_dir']);

        return response()->json($branches->map(fn (Branch $branch) => $this->present($branch)), 200);
    }

    public function store(BranchStoreRequest $request, BranchService $service): JsonResponse
    {
        $branch = $service->create($request->safe()->except('logo'), $request->file('logo'));

        if ($request->boolean('cf')) {
            $service->createFinalConsumer($branch);
        }

        return response()->json($this->present($branch), 201);
    }

    public function update(BranchUpdateRequest $request, Branch $branch, BranchService $service): JsonResponse
    {
        $branch = $service->update($branch, $request->safe()->except('logo'), $request->file('logo'));

        return response()->json($this->present($branch), 200);
    }

    public function destroyLogo(BranchLogoDestroyRequest $request, Branch $branch, BranchService $service): JsonResponse
    {
        return response()->json($this->present($service->removeLogo($branch)), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Branch $branch): array
    {
        return [
            ...$branch->toArray(),
            'logo_url' => $branch->logo_dir ? Storage::disk('public')->url('logos/'.$branch->logo_dir) : null,
        ];
    }
}
