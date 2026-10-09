<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class RegistrationController extends Controller
{
    public function register(RegisterRequest $request, RegistrationService $registrationService): JsonResponse
    {
        $user = $registrationService->register($request->validated());

        return response()->json([
            'succes' => true,
            'message' => 'Registro exitoso. Te enviamos un correo para confirmar tu cuenta.',
            'email' => $user->email,
        ], 201);
    }

    /**
     * Consulta pública de la razón social para el formulario de registro.
     * Solo devuelve la razón social; mismas validaciones de `ruc` que
     * POST register (formato, ya registrado, inexistente en el SRI).
     */
    public function resolveRuc(string $ruc, RegistrationService $registrationService): JsonResponse
    {
        Validator::make(
            ['ruc' => $ruc],
            ['ruc' => (new RegisterRequest)->rules()['ruc']],
            (new RegisterRequest)->messages(),
        )->validate();

        return response()->json([
            'ruc' => $ruc,
            'company' => $registrationService->resolveCompanyName($ruc),
        ]);
    }

    /**
     * Enlace firmado del correo de verificación. Redirige al login del front
     * con `verified=1` (o `verified=0` si el enlace no corresponde).
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);
        $loginUrl = rtrim(config('app.frontend_url'), '/').'/login';

        if (! $user || ! hash_equals(sha1($user->email), $hash)) {
            return redirect()->away($loginUrl.'?verified=0');
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect()->away($loginUrl.'?verified=1');
    }

    /**
     * Reenvía el correo de verificación. Responde igual exista o no el
     * usuario, para no revelar qué cuentas existen.
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['user' => ['required', 'string']]);

        $user = User::where('user', $request->string('user'))->first();

        if ($user && $user->email_verified_at === null) {
            Mail::to($user->email)->send(new VerifyEmail($user));
        }

        return response()->json([
            'succes' => true,
            'message' => 'Si la cuenta existe y no está verificada, te enviamos un nuevo correo de confirmación.',
        ]);
    }
}
