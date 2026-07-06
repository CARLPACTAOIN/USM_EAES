<?php

namespace App\Support;

use App\Models\Event;
use App\Models\User;
use App\Services\AdminScopeService;

class EventTenantScope
{
    public static function apply($query, User $user): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (!$scope) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if ($scope->isUsgAdmin()) {
            $query->whereHas('organization', function ($organizationQuery) use ($scope): void {
                $organizationQuery
                    ->where('type', 'usg')
                    ->orWhere('id', $scope->organizationId);
            });
            return;
        }

        if ($scope->isLsgAdmin()) {
            if (!$scope->collegeId) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereHas('organization', function ($organizationQuery) use ($scope): void {
                $organizationQuery->where('college_id', $scope->collegeId);
            });
            return;
        }

        if (!$scope->organizationId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('organization_id', $scope->organizationId);
    }

    public static function authorize(Event $event, User $user): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (!$scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        $event->loadMissing('organization');

        if ($scope->isUsgAdmin()) {
            if ($event->organization_id === $scope->organizationId || $event->organization?->type === 'usg') {
                return;
            }

            abort(403, 'Access denied. USG Admin restricted to university-wide event boundary.');
        }

        if ($scope->isLsgAdmin()) {
            if ($scope->collegeId && $event->organization && $scope->collegeId === $event->organization->college_id) {
                return;
            }

            abort(403, 'Access denied. LSG Admin restricted to college boundary.');
        }

        if ($event->organization_id !== $scope->organizationId) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }
}
