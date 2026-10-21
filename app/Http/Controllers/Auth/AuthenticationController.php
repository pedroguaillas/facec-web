<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\EmisionPointService;
use App\Services\SubscriptionService;
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

        if ($user->email_verified_at === null) {
            return response()->json([
                'succes' => false,
                'error' => 'email_unverified',
                'message' => 'Confirma tu correo para iniciar sesión. Revisa tu bandeja de entrada o solicita un nuevo enlace.',
                'email' => $user->email,
            ], Response::HTTP_FORBIDDEN);
        }

        $response = [
            'token' => $user->createToken('AUTH_TOKEN')->plainTextToken,
            'token_type' => 'bearer',
            'user' => $user,
            'permissions' => [],
        ];

        if (! $user->isAdmin() && $user->company) {
            $company = $user->company;
            $this->emisionPointService->checkAndCreateFirstPoint($company);
            $response['permissions'] = [
                'inventory' => $company->inventory,
                'allow_negative_stock' => $company->allow_negative_stock,
                'decimal' => $company->decimal,
                'printf' => $company->printf,
                'guia_in_invoice' => $company->guia_in_invoice,
                'import_in_invoice' => $company->import_in_invoice,
                'import_in_invoices' => $company->import_in_invoices,
                ...app(SubscriptionService::class)->planSummary($company),
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
