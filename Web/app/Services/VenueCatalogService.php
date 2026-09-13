<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventWorkflowNotification;
use Illuminate\Support\Facades\DB;

class VenueCatalogService
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Venue
    {
        return DB::transaction(function () use ($actor, $data): Venue {
            $venue = Venue::create($data + ['is_active' => true]);
            $this->auditAndNotify($actor, $venue, 'venue-created');

            return $venue;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Venue $venue, User $actor, array $data): Venue
    {
        return DB::transaction(function () use ($venue, $actor, $data): Venue {
            $venue->update($data);
            $this->auditAndNotify($actor, $venue, 'venue-updated');

            return $venue->fresh('college');
        });
    }

    public function retire(Venue $venue, User $actor): Venue
    {
        $venue->update(['is_active' => false, 'retired_at' => now()]);
        $this->auditAndNotify($actor, $venue, 'venue-retired');

        return $venue;
    }

    public function reactivate(Venue $venue, User $actor): Venue
    {
        $venue->update(['is_active' => true, 'retired_at' => null]);
        $this->auditAndNotify($actor, $venue, 'venue-reactivated');

        return $venue;
    }

    private function auditAndNotify(User $actor, Venue $venue, string $action): void
    {
        AuditLog::create([
            'target_id' => $venue->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => [
                'venue_name' => $venue->name,
                'campus_location' => $venue->campus_location,
                'managing_office' => $venue->managing_office,
                'is_active' => $venue->is_active,
            ],
        ]);

        User::role('Super Admin (OSA)')->get()->each(function (User $recipient) use ($action, $venue): void {
            $recipient->notify(new EventWorkflowNotification([
                'notification_key' => $action.':'.$venue->id.':'.$venue->updated_at?->timestamp,
                'kind' => $action,
                'title' => str($action)->replace('-', ' ')->title()->toString(),
                'body' => $venue->displayName(),
                'action_label' => 'Open Venue Catalog',
                'action_url' => route('dashboard.venues'),
                'severity' => $action === 'venue-retired' ? 'warning' : 'info',
                'needs_action' => false,
            ]));
        });
    }
}
