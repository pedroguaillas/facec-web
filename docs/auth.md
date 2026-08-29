# Autenticación (Backend)

Documentación del módulo de autenticación: login/logout (`App\Http\Controllers\Auth\AuthenticationController`)
y contraseñas — cambio autenticado y recuperación por olvido
(`App\Http\Controllers\Auth\PasswordController`).

## 1. Identidad: `user` (username), no `email`

`users` tiene dos columnas únicas separadas: `user` (username, usado para
login — ver `LoginRequest`) y `email` (solo usado para enviar el correo de
recuperación). Todos los endpoints de contraseña de este módulo reciben
`user`, nunca `email` — el frontend no necesita conocer ni enviar el email
del usuario en ningún momento.

## 2. Endpoints

| Método | Ruta | Auth | Body |
|---|---|---|---|
| POST | `/api/login` | público | `{ user, password }` |
| POST | `/api/logout` | `auth:sanctum` | — |
| POST | `/api/password/forgot` | público, `throttle:6,1` | `{ user }` |
| POST | `/api/password/reset` | público, `throttle:6,1` | `{ user, token, password, password_confirmation }` |
| POST | `/api/password/change` | `auth:sanctum` | `{ current_password, password, password_confirmation }` |

### `POST /api/password/forgot`

Busca `User::where('user', ...)`. **Si no existe → `ValidationException` en
`user` (422, "El usuario no existe.")** — decisión de producto explícita: se
prioriza informar al cliente sobre enumerar usernames. Si existe, dispara el
broker nativo de Laravel (`Password::sendResetLink(['email' => $user->email])`),
que genera el token, lo guarda en `password_reset_tokens` y llama a
`User::sendPasswordResetNotification()`.

Respuesta 200 cuando el usuario existe:
```json
{ "succes": true, "message": "...", "email": "pi***o@hotmail.com" }
```
`email` es la dirección de destino **enmascarada** (`PasswordController::maskEmail()`:
primeros 2 caracteres + último de la parte local, dominio intacto) — confirma
al cliente a qué correo revisar sin exponer la dirección completa a quien
solo conocía el username.

`User::sendPasswordResetNotification()` (`app/Models/User.php`) está
sobrescrito: en vez del `Notification` default de Laravel, envía
`App\Mail\PasswordReset` (el proyecto no usa `Notifications`, solo
`Mailable`s en `app/Mail/*`, igual que `OrderShipped`). El correo arma el
link con `config('app.frontend_url')` (env `FRONTEND_URL`) +
`?token=...&user=...` — el frontend nunca ve el email real del usuario.

### `POST /api/password/reset`

Resuelve `User::where('user', ...)` y delega en
`Password::reset(['email' => $user->email, 'token' => ..., 'password' => ...], $callback)`
— reutiliza el broker/token-repository nativo de Laravel, no reimplementa
generación/validación de tokens. Token inválido, ya usado o expirado →
`ValidationException` en el campo `token` (422).

Expiración del token: **60 minutos** (`config('auth.passwords.users.expire')`,
default de Laravel, sin modificar). El template del correo
(`resources/views/emails/password-reset.blade.php`) lo indica explícitamente
("Este enlace es válido por 60 minutos") — si se cambia el valor en config,
actualizar también el texto del correo.

Al confirmar el reset se **revocan todos los tokens Sanctum** del usuario
(`$user->tokens()->delete()`) — no hay sesión activa en este flujo, así que
no hay un "token actual" que preservar. El usuario debe volver a hacer login.

### `POST /api/password/change`

Requiere `current_password` correcto (validado en
`ChangePasswordRequest::withValidator()` con `Hash::check`, mismo patrón que
`LoginRequest::authenticate()`). Al guardar la nueva contraseña se **revocan
todos los tokens Sanctum excepto el que hizo la request**
(`$user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete()`)
— cierra otras sesiones activas sin desloguear al usuario actual.

## 3. Validación de contraseña

`Illuminate\Validation\Rules\Password::min(8)` en `reset` y `change`. No hay
reglas adicionales (mayúsculas/símbolos) — si se necesitan, añadir con
`->mixedCase()->numbers()->symbols()` en los FormRequests correspondientes.

## 4. Puntos de mejora

- La URL en `config('app.frontend_url')` es un placeholder
  (`http://localhost:3000`) hasta que exista la página `/reset-password` en
  `facec-front-next`. Ajustar `FRONTEND_URL` en `.env`/`.env.production`
  cuando esa página exista — no requiere cambios en el backend.
- El rate limit de `forgot`/`reset` usa el middleware `throttle:6,1` (6
  intentos/minuto por IP); el broker de Laravel además aplica su propio
  throttle interno de 60s por email (`config('auth.passwords.users.throttle')`).
