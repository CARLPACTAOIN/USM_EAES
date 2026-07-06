<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Event;
use App\Models\Organization;
use App\Services\AdminScopeService;
use App\Support\ScannerAccess;

class TenantScopeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = Auth::user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        // 1. Spatie Permission Check
        $hasPermission = ScannerAccess::grantsPermission($user, $permission, $user->currentAccessToken());
        if (!$hasPermission) {
            abort(403, 'Unauthorized action.');
        }

        // Resolve organization constraints from request parameters (event_id, organization_id, etc.)
        $targetOrganizationId = null;
        $targetEvent = null;

        if ($request->has('event_id')) {
            $targetEvent = Event::find($request->input('event_id'));
            if ($targetEvent) {
                $targetOrganizationId = $targetEvent->organization_id;
            }
        } elseif ($request->route('event')) {
            $targetEvent = $request->route('event');
            if (is_string($targetEvent)) {
                $targetEvent = Event::find($targetEvent);
            }
            if ($targetEvent instanceof Event) {
                $targetOrganizationId = $targetEvent->organization_id;
            }
        }

        if ($request->has('organization_id')) {
            $targetOrganizationId = $request->input('organization_id');
        } elseif ($request->route('organization')) {
            $org = $request->route('organization');
            if (is_string($org)) {
                $org = Organization::find($org);
            }
            if ($org instanceof Organization) {
                $targetOrganizationId = $org->id;
            }
        }

        // Enforce boundaries based on roles if a target organization is specified
        if ($targetOrganizationId) {
            $targetOrg = Organization::find($targetOrganizationId);
            if (!$targetOrg) {
                abort(404, 'Target organization not found.');
            }

            if ($permission === 'submit-evaluations') {
                return $next($request);
            }

            if ($targetEvent instanceof Event && ScannerAccess::hasScannerSessionForEvent($user, $targetEvent, $user->currentAccessToken())) {
                return $next($request);
            }

            if ($targetEvent instanceof Event && ScannerAccess::canAccessEvent($user, $targetEvent)) {
                return $next($request);
            }

            $scope = app(AdminScopeService::class)->forUser($user);
            if (!$scope || !$scope->canAccessOrganization($targetOrg)) {
                abort(403, 'Access denied. Restricted to your assigned admin boundary.');
            }
        }

        return $next($request);
    }
}
