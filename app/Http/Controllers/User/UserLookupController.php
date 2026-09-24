<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserLookupController extends Controller
{
    /**
     * Usuarios de la empresa autenticada, candidatos a "responsable" (ej. de
     * una caja). A diferencia de customers/products/providers lookup, sin `q`
     * devuelve la lista completa: la empresa suele tener pocos usuarios y el
     * selector no necesita que el usuario escriba primero.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = Auth::user()->company?->id;

        if (! $companyId) {
            return response()->json([]);
        }

        $term = trim((string) $request->input('q', ''));

        $users = CompanyUser::where('level_id', $companyId)
            ->whereHas('user', function ($query) use ($term) {
                if ($term !== '') {
                    $query->where(function ($q) use ($term) {
                        $q->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
                }
            })
            ->with('user')
            ->limit(50)
            ->get()
            ->pluck('user')
            ->filter()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name ?? $user->user,
                'email' => $user->email,
            ])
            ->values();

        return response()->json($users);
    }
}
