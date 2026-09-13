<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\EventTenantScope;
use Carbon\Carbon;

class DashboardEventWorkspaceService
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventLifecycleService $lifecycles
    ) {}

    public function eventsFor(User $user, string $workspace, ?string $status = null, ?string $search = null, int $perPage = 15)
    {
        $query = $this->baseEventQuery();
        $workspace = $workspace === 'participating' ? 'participating' : 'organized';

        if ($workspace === 'participating') {
            $this->applyParticipatingEventScope($query, $user);
        } else {
            $this->applyEventTenantScope($query, $user);
        }

        if (filled($status)) {
            $query->where('status', $status);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $searchTerm = "%{$search}%";

            $query->where(function ($searchQuery) use ($searchTerm): void {
                $searchQuery
                    ->whereLike('title', $searchTerm)
                    ->orWhereLike('proposal_category', $searchTerm)
                    ->orWhereHas('organization', function ($organizationQuery) use ($searchTerm): void {
                        $organizationQuery
                            ->whereLike('name', $searchTerm)
                            ->orWhereLike('acronym', $searchTerm);
                    });
            });
        }

        $events = $query->latest()->paginate($this->normalizePerPage($perPage));

        $this->ensureOrganizerScopesForDashboard($events->getCollection());
        $this->annotateEventsForDashboard($events->getCollection(), $user, $workspace);

        return $events;
    }

    public function eventForWorkspace(User $user, string $workspace, string $eventId): Event
    {
        $workspace = $workspace === 'participating' ? 'participating' : 'organized';
        $query = $this->baseEventQuery();

        if ($workspace === 'participating') {
            $this->applyParticipatingEventScope($query, $user);
        } else {
            $this->applyEventTenantScope($query, $user);
        }

        $event = $query->find($eventId);

        if (! $event) {
            abort(404);
        }
        $events = collect([$event]);

        $this->ensureOrganizerScopesForDashboard($events);
        $this->annotateEventsForDashboard($events, $user, $workspace);

        return $event;
    }

    public function refreshLifecycleForDashboard(User $user): void
    {
        $today = Carbon::now(config('services.eaes.event_timezone', 'Asia/Manila'))->toDateString();
        $seenEventIds = [];

        foreach (['organized', 'participating'] as $workspace) {
            $query = Event::query()
                ->with(['eventDays', 'organization'])
                ->whereIn('status', ['approved', 'completed'])
                ->whereDate('start_date', '<=', $today)
                ->where(function ($lifecycleQuery): void {
                    $lifecycleQuery
                        ->whereNull('lifecycle_phase')
                        ->orWhere('lifecycle_phase', '!=', EventLifecycleService::PHASE_COMPLETED)
                        ->orWhere('status', 'approved');
                });

            if ($workspace === 'participating') {
                $this->applyParticipatingEventScope($query, $user);
            } else {
                $this->applyEventTenantScope($query, $user);
            }

            $query->chunkById(100, function ($events) use (&$seenEventIds): void {
                foreach ($events as $event) {
                    if (isset($seenEventIds[$event->id])) {
                        continue;
                    }

                    $seenEventIds[$event->id] = true;
                    $this->lifecycles->update($event);
                }
            });
        }
    }

    public function countsFor(User $user): array
    {
        $organized = Event::query();
        $this->applyEventTenantScope($organized, $user);

        $participating = Event::query();
        $this->applyParticipatingEventScope($participating, $user);

        return [
            'organized' => $organized->count(),
            'participating' => $participating->count(),
        ];
    }

    public function currentScopeFor(User $user): ?AdminScope
    {
        return $this->adminScopes->forUser($user);
    }

    public function statusFiltersFor(User $user): array
    {
        return $user->hasRole('Super Admin (OSA)')
            ? [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW, Event::STATUS_REVISION_REQUESTED, Event::STATUS_APPROVED, Event::STATUS_REJECTED, Event::STATUS_COMPLETED]
            : [Event::STATUS_DRAFT, Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW, Event::STATUS_REVISION_REQUESTED, Event::STATUS_APPROVED, Event::STATUS_REJECTED, Event::STATUS_COMPLETED];
    }

    private function baseEventQuery()
    {
        return Event::with([
            'organization',
            'venue',
            'permitVersions.approver',
            'eventDays',
            'evaluationFormVersion',
            'parentEvent.organization',
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.delegatedOrganization',
            'scannerSessions' => function ($query): void {
                $query->with(['scannerUser', 'participationScope.organization'])
                    ->withCount([
                        'rawScans as pending_scans_count' => fn ($query) => $query->whereNull('processed_at'),
                        'rawScans as synced_scans_count',
                        'rawScans as failed_scans_count' => fn ($query) => $query->whereNotNull('rejection_reason'),
                    ])
                    ->latest();
            },
            'scannerAssignments' => function ($query): void {
                $query->with(['participationScope.organization', 'scannerUser.organization', 'assignedBy', 'revokedBy', 'scannerSessions'])
                    ->latest();
            },
        ])
            ->withCount([
                'evaluations as unprocessed_comment_count' => function ($query): void {
                    $query->pendingSentimentComments();
                },
            ]);
    }

    private function applyEventTenantScope($query, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        EventTenantScope::apply($query, $user);

        if ($scope?->isSuperAdmin()) {
            $query->where('status', '!=', 'draft');
        }
    }

    private function applyParticipatingEventScope($query, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (! $scope) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query
            ->where('status', '!=', 'draft')
            ->whereHas('participationScopes', function ($scopeQuery) use ($scope): void {
                $scopeQuery
                    ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                    ->where('status', EventParticipationScope::STATUS_ACTIVE);

                $this->applyParticipantScopeBoundary($scopeQuery, $scope);
            });
    }

    private function applyParticipantScopeBoundary($query, AdminScope $scope): void
    {
        if ($scope->isSuperAdmin()) {
            return;
        }

        $query->where(function ($boundaryQuery) use ($scope): void {
            $hasBoundary = false;

            if ($scope->organizationId) {
                $hasBoundary = true;
                $boundaryQuery
                    ->where('organization_id', $scope->organizationId)
                    ->orWhere('delegated_to_organization_id', $scope->organizationId);
            }

            if ($scope->isLsgAdmin() && $scope->collegeId) {
                $hasBoundary = true;
                $boundaryQuery
                    ->orWhere('college_id', $scope->collegeId)
                    ->orWhereHas('organization', fn ($organizationQuery) => $organizationQuery->where('college_id', $scope->collegeId))
                    ->orWhereHas('delegatedOrganization', fn ($organizationQuery) => $organizationQuery->where('college_id', $scope->collegeId));
            }

            if (! $hasBoundary) {
                $boundaryQuery->whereRaw('1 = 0');
            }
        });
    }

    private function annotateEventsForDashboard($events, User $user, string $workspace): void
    {
        $adminScope = $this->adminScopes->forUser($user);

        $events->each(function (Event $event) use ($adminScope, $user, $workspace): void {
            $event->setAttribute('attendance_policy_locked', $this->attendancePolicyLocked($event));

            $event->participationScopes->each(function (EventParticipationScope $scope) use ($adminScope, $user): void {
                $scope->setAttribute('current_admin_owns_scope', $this->adminScopeMatchesParticipantScope($adminScope, $scope));
                $scope->setAttribute('current_admin_can_manage_scope', $this->participationScopes->canManageScope($user, $scope));
            });

            if ($workspace !== 'participating') {
                return;
            }

            $visibleScopes = $event->participationScopes
                ->filter(fn (EventParticipationScope $scope) => $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT
                    && $scope->status === EventParticipationScope::STATUS_ACTIVE
                    && $scope->current_admin_owns_scope)
                ->values();
            $visibleScopeIds = $visibleScopes->pluck('id')->all();

            $event->setRelation('participationScopes', $visibleScopes);
            $event->setRelation('scannerAssignments', $event->scannerAssignments
                ->filter(fn ($assignment) => in_array($assignment->event_participation_scope_id, $visibleScopeIds, true))
                ->values());
            $event->setRelation('scannerSessions', $event->scannerSessions
                ->filter(fn ($session) => in_array($session->event_participation_scope_id, $visibleScopeIds, true))
                ->values());
        });
    }

    private function attendancePolicyLocked(Event $event): bool
    {
        $event->loadMissing('eventDays');
        $timezone = config('services.eaes.event_timezone', 'Asia/Manila');
        $eventStartDate = $event->start_date?->copy()->startOfDay();
        $eventEndDate = $event->end_date?->copy()->startOfDay() ?? $eventStartDate;
        $startsAt = $event->eventDays
            ->filter(function (EventDay $day) use ($eventStartDate, $eventEndDate): bool {
                if (! $eventStartDate || ! $eventEndDate || ! $day->date) {
                    return true;
                }

                $dayDate = $day->date->copy()->startOfDay();

                return $dayDate->betweenIncluded($eventStartDate, $eventEndDate);
            })
            ->map(function (EventDay $day) use ($timezone): ?Carbon {
                if (! $day->date || ! $day->start_time) {
                    return null;
                }

                return Carbon::parse($day->date->toDateString().' '.$day->start_time, $timezone);
            })
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->first();

        if (! $startsAt && $event->start_date) {
            $firstStartTime = $event->eventDays
                ->sortBy('day_number')
                ->first(fn (EventDay $day) => filled($day->start_time))
                ?->start_time;

            if ($firstStartTime) {
                $startsAt = Carbon::parse($event->start_date->toDateString().' '.$firstStartTime, $timezone);
            }
        }

        if (! $startsAt && $event->start_date) {
            $startsAt = Carbon::parse($event->start_date->toDateString(), $timezone)->startOfDay();
        }

        if ($startsAt) {
            return Carbon::now($timezone)->greaterThanOrEqualTo($startsAt);
        }

        return in_array($event->lifecycle_phase, [
            'active',
            'ended',
            'processing',
            'evaluation_open',
            'evaluation_closed',
            'completed',
        ], true);
    }

    private function ensureOrganizerScopesForDashboard($events): void
    {
        $events->each(function (Event $event): void {
            $hasOrganizerScope = $event->participationScopes
                ->contains('participation_role', EventParticipationScope::ROLE_ORGANIZER);

            if ($hasOrganizerScope) {
                return;
            }

            $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']));
            $event->load([
                'participationScopes.organization',
                'participationScopes.college',
                'participationScopes.delegatedOrganization',
            ]);
        });
    }

    private function adminScopeMatchesParticipantScope(?AdminScope $adminScope, EventParticipationScope $scope): bool
    {
        if (! $adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
        }

        if ($scope->delegatedOrganization && $adminScope->canAccessOrganization($scope->delegatedOrganization)) {
            return true;
        }

        return $scope->college_id && $adminScope->isLsgAdmin() && $adminScope->collegeId === $scope->college_id;
    }

    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, [10, 15, 25, 50], true) ? $perPage : 15;
    }
}
