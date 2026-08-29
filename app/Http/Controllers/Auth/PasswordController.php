<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('user', $request->string('user'))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user' => 'El usuario no existe.',
            ]);
        }

        Password::sendResetLink(['email' => $user->email]);

        return response()->json([
            'succes' => true,
            'message' => 'Se envió un correo con instrucciones para restablecer la contraseña.',
            'email' => $this->maskEmail($user->email),
        ], Response::HTTP_OK);
    }

    /**
     * Enmascara la parte local del email dejando visibles los primeros 2
     * caracteres y el último, para confirmar al usuario a qué correo revisar
     * sin exponer la dirección completa (ver docs/auth.md §2).
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visibleStart = mb_substr($local, 0, 2);
        $visibleEnd = mb_strlen($local) > 2 ? mb_substr($local, -1) : '';

        return "{$visibleStart}***{$visibleEnd}@{$domain}";
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('user', $request->string('user'))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => 'El token no es válido o ha expirado.',
            ]);
        }

        $status = Password::reset(
            [
                'email' => $user->email,
                'token' => $request->string('token'),
                'password' => $request->string('password'),
                'password_confirmation' => $request->string('password_confirmation'),
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'El token no es válido o ha expirado.',
            ]);
        }

        return response()->json([
            'succes' => true,
            'message' => 'Contraseña actualizada correctamente. Inicia sesión con tu nueva contraseña.',
        ], Response::HTTP_OK);
    }

    /**
     * Cambio de contraseña de un usuario autenticado. Revoca todos los demás
     * tokens Sanctum para cerrar cualquier otra sesión activa.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['password' => $request->string('password')])->save();

        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'succes' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ], Response::HTTP_OK);
    }
}
