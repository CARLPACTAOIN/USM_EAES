<?php

namespace App\Support;

use App\Models\Event;
use App\Models\User;

class EventTenantScope
{
    public static function apply($query, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        if ($user->hasRole('USG Admin')) {
            $query->whereHas('organization', function ($organizationQuery) use ($user): void {
                $organizationQuery
                    ->where('type', 'usg')
                    ->orWhere('id', $user->organization_id);
            });
            return;
        }

        if ($user->hasRole('LSG Admin')) {
            $user->loadMissing('organization');
            $query->whereHas('organization', function ($organizationQuery) use ($user): void {
                $organizationQuery->where('college_id', $user->organization?->college_id);
            });
            return;
        }

        $query->where('organization_id', $user->organization_id);
    }

    public static function authorize(Event $event, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        $event->loadMissing('organization');
        $user->loadMissing('organization');

        if ($user->hasRole('USG Admin')) {
            if ($event->organization_id === $user->organization_id || $event->organization?->type === 'usg') {
                return;
            }

            abort(403, 'Access denied. USG Admin restricted to university-wide event boundary.');
        }

        if ($user->hasRole('LSG Admin')) {
            if ($user->organization && $event->organization && $user->organization->college_id === $event->organization->college_id) {
                return;
            }

            abort(403, 'Access denied. LSG Admin restricted to college boundary.');
        }

        if ($event->organization_id !== $user->organization_id) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }
}
