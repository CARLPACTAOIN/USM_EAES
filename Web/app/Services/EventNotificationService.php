<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\User;
use App\Notifications\EventWorkflowNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class EventNotificationService
{
    public function __construct(private readonly AdminScopeService $adminScopes)
    {
    }

    public function notifyParticipantScopeInvitation(EventParticipationScope $scope): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization', 'college']);

        if ($scope->participation_role !== EventParticipationScope::ROLE_PARTICIPANT || $scope->event?->status === 'draft') {
            return 0;
        }

        return $this->notifyUsers(
            $this->participantRecipientAdmins($scope),
            'participant_scope_invitation:' . $scope->id,
            'participant_scope_invitation',
            $scope,
            [
                'title' => 'Participant invitation needs acknowledgement',
                'body' => "{$scope->event->title} invited {$scope->displayName()} to submit a participant PPA.",
                'action_label' => 'Open Participating Events',
                'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                'severity' => 'warning',
                'needs_action' => true,
            ]
        );
    }

    public function notifyPendingInvitationsForEvent(Event $event): int
    {
        $event->loadMissing('participationScopes.organization', 'participationScopes.delegatedOrganization', 'organization');

        if ($event->status === 'draft') {
            return 0;
        }

        return $event->participationScopes
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->sum(fn (EventParticipationScope $scope): int => $this->notifyParticipantScopeInvitation($scope));
    }

    public function acknowledgeInvitation(EventParticipationScope $scope, User $actor): bool
    {
        if (!$this->canAcknowledgeInvitation($actor, $scope)) {
            return false;
        }

        if ($scope->invitation_acknowledged_at) {
            return true;
        }

        $scope->update([
            'invitation_acknowledged_at' => now(),
            'invitation_acknowledged_by' => $actor->id,
        ]);

        $this->notifyUsers(
            $this->organizerAndOsaRecipients($scope),
            'participant_scope_acknowledged:' . $scope->id,
            'participant_scope_acknowledged',
            $scope->fresh(['event.organization', 'organization', 'delegatedOrganization']),
            [
                'title' => 'Invitation acknowledged',
                'body' => "{$scope->displayName()} acknowledged the invitation for {$scope->event->title}.",
                'action_label' => 'View scopes',
                'action_url' => route('dashboard.events'),
                'severity' => 'success',
                'needs_action' => false,
            ]
        );

        return true;
    }

    public function notifyParticipantScopeSubmitted(EventParticipationScope $scope): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);

        return $this->notifyUsers(
            $this->organizerAndOsaRecipients($scope),
            'participant_scope_submitted:' . $scope->id . ':' . optional($scope->submitted_at)->timestamp,
            'participant_scope_submitted',
            $scope,
            [
                'title' => 'Participant PPA submitted',
                'body' => "{$scope->displayName()} submitted a participant PPA for {$scope->event->title}.",
                'action_label' => 'Review scopes',
                'action_url' => route('dashboard.events'),
                'severity' => 'info',
                'needs_action' => true,
            ]
        );
    }

    public function notifyParticipantScopeReviewed(EventParticipationScope $scope, string $status): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);
        $label = ucwords(str_replace('_', ' ', $status));
        $needsAction = $status === EventParticipationScope::PPA_REJECTED;

        return $this->notifyUsers(
            $this->participantRecipientAdmins($scope)->merge($this->organizerRecipients($scope))->unique('id'),
            "participant_scope_{$status}:{$scope->id}:" . optional($scope->reviewed_at)->timestamp,
            "participant_scope_{$status}",
            $scope,
            [
                'title' => "Participant PPA {$label}",
                'body' => "{$scope->displayName()} is now {$label} for {$scope->event->title}.",
                'action_label' => $needsAction ? 'Resubmit PPA' : 'View event',
                'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                'severity' => $status === EventParticipationScope::PPA_APPROVED ? 'success' : ($needsAction ? 'warning' : 'info'),
                'needs_action' => $needsAction,
            ]
        );
    }

    public function sendParticipantScopeReminders(): int
    {
        $today = now()->toDateString();
        $sent = 0;

        EventParticipationScope::query()
            ->with(['event.organization', 'organization', 'delegatedOrganization', 'college'])
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
            ->where(function ($query): void {
                $query->whereNull('invitation_acknowledged_at')
                    ->orWhere(function ($query): void {
                        $query->where('ppa_required', true)
                            ->whereIn('ppa_status', [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REJECTED]);
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('invitation_last_reminded_at')
                    ->orWhereDate('invitation_last_reminded_at', '<', now()->toDateString());
            })
            ->chunkById(100, function ($scopes) use ($today, &$sent): void {
                foreach ($scopes as $scope) {
                    $sent += $this->notifyUsers(
                        $this->participantRecipientAdmins($scope),
                        "participant_scope_reminder:{$scope->id}:{$today}",
                        'participant_scope_reminder',
                        $scope,
                        [
                            'title' => 'Participant invitation reminder',
                            'body' => "{$scope->event->title} still needs acknowledgement or participant PPA action.",
                            'action_label' => 'Open Participating Events',
                            'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                            'severity' => 'warning',
                            'needs_action' => true,
                        ]
                    );

                    $scope->forceFill(['invitation_last_reminded_at' => now()])->save();
                }
            });

        return $sent;
    }

    public function canAcknowledgeInvitation(User $user, EventParticipationScope $scope): bool
    {
        $scope->loadMissing(['event', 'organization', 'delegatedOrganization']);
        $adminScope = $this->adminScopes->forUser($user);

        return $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT
            && $scope->status === EventParticipationScope::STATUS_ACTIVE
            && $scope->event?->status !== 'draft'
            && $adminScope
            && !$adminScope->isSuperAdmin()
            && $this->adminScopeOwnsParticipantScope($adminScope, $scope);
    }

    public function summaryForUser(User $user): array
    {
        return [
            'unread_count' => $user->unreadNotifications()->count(),
            'needs_action_count' => $this->needsActionItemsForUser($user)->count(),
        ];
    }

    public function needsActionItemsForUser(User $user, int $limit = 6): Collection
    {
        $adminScope = $this->adminScopes->forUser($user);

        if (!$adminScope) {
            return collect();
        }

        if ($adminScope->isSuperAdmin()) {
            return EventParticipationScope::query()
                ->with(['event.organization', 'organization'])
                ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                ->where('status', EventParticipationScope::STATUS_ACTIVE)
                ->where('ppa_status', EventParticipationScope::PPA_SUBMITTED)
                ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
                ->latest('submitted_at')
                ->limit($limit)
                ->get()
                ->map(fn (EventParticipationScope $scope): array => $this->scopeActionItem($scope, 'Participant PPA submitted', route('dashboard.events')));
        }

        return EventParticipationScope::query()
            ->with(['event.organization', 'organization', 'delegatedOrganization'])
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
            ->get()
            ->filter(fn (EventParticipationScope $scope): bool => $this->adminScopeOwnsParticipantScope($adminScope, $scope))
            ->filter(fn (EventParticipationScope $scope): bool => !$scope->invitation_acknowledged_at
                || ($scope->ppa_required && in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REJECTED], true)))
            ->take($limit)
            ->map(fn (EventParticipationScope $scope): array => $this->scopeActionItem(
                $scope,
                $scope->invitation_acknowledged_at ? 'Participant PPA needs action' : 'Invitation needs acknowledgement',
                route('dashboard.events', ['workspace' => 'participating'])
            ))
            ->values();
    }

    private function notifyUsers(Collection $users, string $key, string $kind, EventParticipationScope $scope, array $payload): int
    {
        $sent = 0;

        foreach ($users->unique('id') as $user) {
            if ($this->hasNotificationKey($user, $key)) {
                continue;
            }

            $user->notify(new EventWorkflowNotification($this->payload($key, $kind, $scope, $payload)));
            $sent++;
        }

        return $sent;
    }

    private function payload(string $key, string $kind, EventParticipationScope $scope, array $payload): array
    {
        $scope->loadMissing(['event', 'organization']);

        return array_merge([
            'notification_key' => $key,
            'kind' => $kind,
            'event_id' => $scope->event_id,
            'event_title' => $scope->event?->title,
            'participation_scope_id' => $scope->id,
            'participant_name' => $scope->displayName(),
            'created_label' => now()->diffForHumans(),
        ], $payload);
    }

    private function hasNotificationKey(User $user, string $key): bool
    {
        return $user->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => ($notification->data['notification_key'] ?? null) === $key);
    }

    private function participantRecipientAdmins(EventParticipationScope $scope): Collection
    {
        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if (!$scope->organization_id && !$scope->delegated_to_organization_id && !$scope->college_id) {
            return collect();
        }

        return AdminAssignment::query()
            ->with('user')
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query) use ($scope): void {
                if ($scope->organization_id) {
                    $query->orWhere('organization_id', $scope->organization_id);
                }

                if ($scope->delegated_to_organization_id) {
                    $query->orWhere('organization_id', $scope->delegated_to_organization_id);
                }

                if ($scope->college_id) {
                    $query->orWhere(function ($query) use ($scope): void {
                        $query->where('role_name', 'LSG Admin')
                            ->where('college_id', $scope->college_id);
                    });
                }
            })
            ->get()
            ->pluck('user')
            ->filter();
    }

    private function organizerAndOsaRecipients(EventParticipationScope $scope): Collection
    {
        return $this->organizerRecipients($scope)->merge($this->osaRecipients())->unique('id')->values();
    }

    private function organizerRecipients(EventParticipationScope $scope): Collection
    {
        $scope->loadMissing('event.organization');
        $organizationId = $scope->event?->organization_id;

        if (!$organizationId) {
            return collect();
        }

        return AdminAssignment::query()
            ->with('user')
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('organization_id', $organizationId)
            ->get()
            ->pluck('user')
            ->filter();
    }

    private function osaRecipients(): Collection
    {
        return User::role('Super Admin (OSA)')->get();
    }

    private function adminScopeOwnsParticipantScope($adminScope, EventParticipationScope $scope): bool
    {
        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
        }

        if ($scope->delegatedOrganization && $adminScope->canAccessOrganization($scope->delegatedOrganization)) {
            return true;
        }

        return $scope->college_id && $adminScope->isLsgAdmin() && $adminScope->collegeId === $scope->college_id;
    }

    private function scopeActionItem(EventParticipationScope $scope, string $label, string $url): array
    {
        return [
            'label' => $label,
            'event_title' => $scope->event?->title,
            'participant_name' => $scope->displayName(),
            'status' => $scope->ppa_status,
            'url' => $url,
        ];
    }
}
