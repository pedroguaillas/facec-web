<?php

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\BankStoreRequest;
use App\Http\Requests\Bank\BankUpdateRequest;
use App\Http\Resources\BankResources;
use App\Models\Treasury\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class BankController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->input('search', '');
        $paginate = (int) $request->input('paginate', 15);

        $banks = Bank::when($search, function ($query) use ($search) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);

            $query->where('name', 'LIKE', "%{$escaped}%");
        })
            ->latest()
            ->paginate($paginate)
            ->withQueryString();

        return BankResources::collection($banks);
    }

    public function store(BankStoreRequest $request): JsonResponse
    {
        $branch = Auth::user()->company->branches()->orderBy('created_at')->first();

        $bank = Bank::create([
            ...$request->validated(),
            'branch_id' => $branch->id,
        ]);

        return response()->json($bank, 201);
    }

    public function edit(Bank $bank): JsonResponse
    {
        return response()->json($bank);
    }

    public function update(BankUpdateRequest $request, Bank $bank): JsonResponse
    {
        $bank->update($request->validated());

        return response()->json($bank);
    }

    public function destroy(Bank $bank): JsonResponse
    {
        if ($bank->bankAccounts()->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'No se puede eliminar un banco con cuentas bancarias registradas.',
            ], 422);
        }

        $bank->delete();

        return response()->json([
            'succes' => true,
            'message' => 'Banco eliminado con éxito.',
        ]);
    }
}
