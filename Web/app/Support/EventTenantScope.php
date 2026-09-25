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

        if (! $scope) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if (! $scope->organizationId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('organization_id', $scope->organizationId);
    }

    public static function authorize(Event $event, User $user): void
    {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (! $scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        $event->loadMissing('organization');

        if ($event->organization_id !== $scope->organizationId) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }
}
