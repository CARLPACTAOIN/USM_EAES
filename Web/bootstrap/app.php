<?php

use App\Http\Middleware\EnsureAuthorityContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantScopeMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Predis\Connection\ConnectionException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'authority.context' => EnsureAuthorityContext::class,
            'tenant.scope' => TenantScopeMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ConnectionException $exception, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            $retryAfter = config('services.eaes.redis_unavailable_retry_after', 30);

            return response()->json([
                'success' => false,
                'message' => 'Service coordination is temporarily unavailable. Please retry shortly.',
                'retry_after' => $retryAfter,
            ], 503, ['Retry-After' => $retryAfter]);
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            $headers = $exception->getHeaders();

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please retry later.',
                'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
            ], 429, $headers);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return response()->view('errors.route-status', [
                'status' => 405,
                'title' => 'This action cannot be opened directly',
                'message' => 'This address is reserved for a form submission inside EAES. Use the page controls instead of opening the URL manually.',
                'tone' => 'warning',
            ], 405);
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 403 || $request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return response()->view('errors.route-status', [
                'status' => 403,
                'title' => 'Access restricted',
                'message' => 'Your account does not have access to this workspace or action. Open the correct portal area for your assigned role.',
                'tone' => 'danger',
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return response()->view('errors.route-status', [
                'status' => 404,
                'title' => 'Page not found',
                'message' => 'The page may have moved, the URL may be incomplete, or your account may need to open it from the proper workspace.',
                'tone' => 'default',
            ], 404);
        });
    })->create();
