<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'tenant.scope' => \App\Http\Middleware\TenantScopeMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
