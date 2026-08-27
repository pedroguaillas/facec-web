<?php

use App\Http\Middleware\CheckRoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectTo(
            guests: fn (Request $request) => $request->is('api/*') ? null : null
        );

        $middleware->alias([
            'role' => CheckRoleMiddleware::class,
        ]);

        // Caddy termina TLS y reenvía a `app` por HTTP dentro de la red de
        // docker (ver compose.prod.yaml / docker/production/Caddyfile). Sin
        // confiar en el proxy, Request::isSecure() da false y Laravel genera
        // URLs http:// (paginación, etc.) que el navegador bloquea como
        // mixed content en una página https. `app` no tiene puerto publicado,
        // solo Caddy le llega, así que confiar en '*' es seguro acá.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'error' => 'No tienes permiso para modificar este recurso.',
                ], 403);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'error' => 'Se requiere un token de acceso válido para esta solicitud.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'error' => 'No tienes permiso para realizar esta acción.',
                ], Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                $modelName = class_basename($e->getModel());

                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'error' => "No se pudo encontrar el recurso ($modelName) solicitado.",
                ], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                    'error' => 'Recurso no encontrador.',
                ], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Error interno del servidor.',
                    'error' => config('app.debug') ? $e->getMessage() : 'Ha ocurrido un error inesperado.',
                    'trace' => config('app.debug') ? $e->getTrace() : null,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        });
    })->create();
