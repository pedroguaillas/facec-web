<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\EmisionPointService;
use App\StaticClasses\PlanTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticationController extends Controller
{
    public function __construct(private EmisionPointService $emisionPointService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        /** @var User $user */
        $user = Auth::user();

        $response = [
            'token' => $user->createToken('AUTH_TOKEN')->plainTextToken,
            'token_type' => 'bearer',
            'user' => $user,
            'permissions' => [],
        ];

        if (! $user->isAdmin() && $user->company) {
            $company = $user->company;
            $this->emisionPointService->checkAndCreateFirstPoint($company);
            $subscription = $company->currentSubscription();

            $response['permissions'] = [
                'inventory' => $company->inventory,
                'decimal' => $company->decimal,
                'printf' => $company->printf,
                'guia_in_invoice' => $company->guia_in_invoice,
                'import_in_invoice' => $company->import_in_invoice,
                'import_in_invoices' => $company->import_in_invoices,
                'plan' => $subscription?->plan,
                'plan_ends_at' => $subscription?->ended_at?->toDateString(),
                'financiero' => $subscription !== null && in_array($subscription->plan, PlanTypes::FINANCIERO_LEVEL, true),
            ];
        }

        return response()->json($response, Response::HTTP_OK);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Sesión cerrada con éxito.',
        ], Response::HTTP_OK);
    }
}
