<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user' => ['required'],
            'password' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'user.required' => 'El usuario es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * No usa Auth::attempt() porque las rutas de la API son stateless
     * (sin middleware de sesión) — resolvemos el usuario a mano y lo
     * fijamos en el guard solo para el ciclo de vida de este request.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('user', $this->string('user'))->first();

        if (! $user || ! Hash::check($this->string('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'user' => 'Las credenciales proporcionadas son incorrectas.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::setUser($user);
    }

    /**
     * Ensure the login request is not rate limited.
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'user' => "Demasiados intentos. Intenta de nuevo en {$seconds} segundos.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('user')).'|'.$this->ip());
    }
}
