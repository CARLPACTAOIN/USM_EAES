<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Event;
use App\Models\Organization;
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

        // 2. Tenancy Boundary Check
        // Super Admin (OSA) has universal access, skip tenancy checks
        if (ScannerAccess::hasRole($user, 'Super Admin (OSA)')) {
            return $next($request);
        }

        // Resolve organization constraints from request parameters (event_id, organization_id, etc.)
        $targetOrganizationId = null;

        if ($request->has('event_id')) {
            $event = Event::find($request->input('event_id'));
            if ($event) {
                $targetOrganizationId = $event->organization_id;
            }
        } elseif ($request->route('event')) {
            $event = $request->route('event');
            if (is_string($event)) {
                $event = Event::find($event);
            }
            if ($event instanceof Event) {
                $targetOrganizationId = $event->organization_id;
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

            $userOrg = Organization::find($user->organization_id);
            if (!$userOrg && !$user->hasRole('Super Admin (OSA)')) {
                abort(403, 'User does not belong to any organization.');
            }

            if (ScannerAccess::hasRole($user, 'USG Admin')) {
                // USG Admin can access USG events or university-wide scope
                if ($targetOrg->type !== 'usg' && $targetOrg->id !== $user->organization_id) {
                    abort(403, 'Access denied. USG Admin is restricted to university-wide events.');
                }
            } elseif (ScannerAccess::hasRole($user, 'LSG Admin')) {
                // LSG Admin can access constituent societies inside their College boundary
                if ($targetOrg->college_id !== $userOrg->college_id) {
                    abort(403, 'Access denied. LSG Admin is restricted to their constituent college boundary.');
                }
            } elseif (ScannerAccess::hasRole($user, 'ARO Admin') || ScannerAccess::hasRole($user, 'Society Admin') || ScannerAccess::hasRole($user, 'Scanner')) {
                // Strictly restricted to their own assigned organization
                if ($targetOrg->id !== $user->organization_id) {
                    abort(403, 'Access denied. Restricted to your assigned organization boundary.');
                }
            }
        }

        return $next($request);
    }
}
