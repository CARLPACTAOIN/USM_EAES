<?php

namespace App\Http\Middleware;

use App\Services\AdminScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthorityContext
{
    public function __construct(private readonly AdminScopeService $adminScopes) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            if ($this->adminScopes->lacksAuthorityAssignment($request->user())) {
                if ($request->routeIs('dashboard')) {
                    return redirect()->guest(route('authority-context.index'));
                }

                abort(403, 'No active authority projection is assigned to this account.');
            }

            if ($this->adminScopes->requiresSelection($request->user())) {
                return redirect()->guest(route('authority-context.index'));
            }
        }

        return $next($request);
    }
}
