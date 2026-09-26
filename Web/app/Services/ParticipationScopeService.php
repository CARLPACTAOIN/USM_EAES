<?php

namespace App\Services;

use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ParticipationScopeService
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly EventNotificationService $notifications,
    ) {}

    public function ensureOrganizerScope(Event $event): EventParticipationScope
    {
        $event->loadMissing('organization');

        $organization = $event->organization;
        $isHrDomain = $event->event_domain === 'hr' || $organization?->type === 'hr_unit';

        $organizerScope = $event->participationScopes()->firstOrCreate([
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ], [
            'school_year_id' => $event->school_year_id,
            'department_id' => $event->department_id ?: $organization?->department_id,
            'participant_type' => $this->participantTypeForOrganization($organization),
            'organization_id' => $organization?->id,
            'college_id' => $organization?->college_id,
            'participant_name_snapshot' => $this->participantName($organization, 'Organizer'),
            'ppa_required' => ! $isHrDomain,
            'ppa_status' => $isHrDomain ? EventParticipationScope::PPA_NOT_REQUIRED : $this->ppaStatusFromEventStatus($event->status),
            'ppa_softcopy_path' => $event->proposal_document_path,
            'ppa_softcopy_original_name' => $event->proposal_document_original_name,
            'hardcopy_submitted' => (bool) $event->hardcopy_submitted,
            'org_head_signed' => (bool) $event->head_organization_signed,
            'adviser_signed' => (bool) $event->adviser_signed,
            'approved_at' => in_array($event->status, [Event::STATUS_APPROVED, Event::STATUS_COMPLETED], true) ? now() : null,
            'capture_mode' => in_array($organization?->type, ['usg', 'lsg', 'hr_unit'], true) ? 'central_gate' : 'self_scanning',
            'analytics_rollup_enabled' => true,
        ]);

        $updates = [];
        if ($organizerScope->school_year_id === null && $event->school_year_id !== null) {
            $updates['school_year_id'] = $event->school_year_id;
        }
        $targetDeptId = $event->department_id ?: $organization?->department_id;
        if ($organizerScope->department_id === null && $targetDeptId !== null) {
            $updates['department_id'] = $targetDeptId;
        }
        if (! empty($updates)) {
            $organizerScope->update($updates);
        }

        return $organizerScope;
    }

    /**
     * @param  list<string>  $organizationIds
     * @param  list<string>  $organizationIds
     * @param  list<string>  $collegeIds
     * @param  list<string>  $departmentIds
     * @return array{created_count: int, skipped_organizer_count: int, skipped_existing_count: int}
     */
    public function createParticipantScopes(
        Event $event,
        string $audienceMode,
        array $organizationIds,
        array $collegeIds,
        string $captureMode,
        bool $ppaRequired,
        bool $analyticsRollupEnabled,
        ?string $programId = null,
        ?string $participantNameSnapshot = null,
        array $departmentIds = [],
    ): array {
        $organizationIds = collect($organizationIds)->filter()->unique()->values();
        $collegeIds = collect($collegeIds)->filter()->unique()->values();
        $departmentIds = collect($departmentIds)->filter()->unique()->values();

        if ($audienceMode === 'all') {
            $collegeIds = College::query()->orderBy('name')->pluck('id')->values();
        }

        $createdScopes = collect();
        $skippedOrganizerCount = 0;
        $skippedExistingCount = 0;

        DB::transaction(function () use (
            $analyticsRollupEnabled,
            $audienceMode,
            $captureMode,
            $collegeIds,
            $departmentIds,
            $event,
            $organizationIds,
            $ppaRequired,
            $participantNameSnapshot,
            $programId,
            &$createdScopes,
            &$skippedExistingCount,
            &$skippedOrganizerCount,
        ): void {
            $parentScope = $this->ensureOrganizerScope($event);

            if ($audienceMode === 'organization') {
                Organization::query()
                    ->whereIn('id', $organizationIds)
                    ->orderBy('name')
                    ->get()
                    ->each(function (Organization $organization) use (
                        $analyticsRollupEnabled,
                        $captureMode,
                        $event,
                        $organizationIds,
                        $parentScope,
                        $ppaRequired,
                        $participantNameSnapshot,
                        $programId,
                        &$createdScopes,
                        &$skippedExistingCount,
                        &$skippedOrganizerCount,
                    ): void {
                        if ($organization->id === $event->organization_id) {
                            $skippedOrganizerCount++;

                            return;
                        }

                        $participantScope = $event->participationScopes()->firstOrCreate([
                            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                            'organization_id' => $organization->id,
                            'status' => EventParticipationScope::STATUS_ACTIVE,
                        ], [
                            'parent_scope_id' => $parentScope->id,
                            'school_year_id' => $event->school_year_id,
                            'participant_type' => $this->participantTypeForOrganization($organization),
                            'college_id' => $organization->college_id,
                            'department_id' => $organization->department_id,
                            'program_id' => $programId,
                            'participant_name_snapshot' => $organizationIds->count() === 1 && $participantNameSnapshot
                                ? $participantNameSnapshot
                                : $this->participantName($organization, 'Participant scope'),
                            'ppa_required' => $ppaRequired,
                            'ppa_status' => $ppaRequired ? EventParticipationScope::PPA_DRAFT : EventParticipationScope::PPA_NOT_REQUIRED,
                            'capture_mode' => $captureMode,
                            'analytics_rollup_enabled' => $analyticsRollupEnabled,
                        ]);

                        if (! $participantScope->wasRecentlyCreated) {
                            $skippedExistingCount++;

                            return;
                        }

                        $createdScopes->push($participantScope);
                    });

                return;
            }

            if ($audienceMode === 'department') {
                Department::query()
                    ->whereIn('id', $departmentIds)
                    ->orderBy('name')
                    ->get()
                    ->each(function (Department $department) use (
                        $analyticsRollupEnabled,
                        $captureMode,
                        $event,
                        $parentScope,
                        $ppaRequired,
                        &$createdScopes,
                        &$skippedExistingCount,
                    ): void {
                        $participantScope = $event->participationScopes()->firstOrCreate([
                            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                            'participant_type' => 'department',
                            'organization_id' => null,
                            'department_id' => $department->id,
                            'status' => EventParticipationScope::STATUS_ACTIVE,
                        ], [
                            'parent_scope_id' => $parentScope->id,
                            'school_year_id' => $event->school_year_id,
                            'college_id' => $department->college_id,
                            'program_id' => null,
                            'participant_name_snapshot' => trim(($department->code ? "{$department->code} - " : '').$department->name),
                            'ppa_required' => $ppaRequired,
                            'ppa_status' => $ppaRequired ? EventParticipationScope::PPA_DRAFT : EventParticipationScope::PPA_NOT_REQUIRED,
                            'capture_mode' => $captureMode,
                            'analytics_rollup_enabled' => $analyticsRollupEnabled,
                        ]);

                        if (! $participantScope->wasRecentlyCreated) {
                            $skippedExistingCount++;

                            return;
                        }

                        $createdScopes->push($participantScope);
                    });

                return;
            }

            College::query()
                ->whereIn('id', $collegeIds)
                ->orderBy('name')
                ->get()
                ->each(function (College $college) use (
                    $analyticsRollupEnabled,
                    $captureMode,
                    $event,
                    $parentScope,
                    $ppaRequired,
                    &$createdScopes,
                    &$skippedExistingCount,
                ): void {
                    $participantScope = $event->participationScopes()->firstOrCreate([
                        'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                        'participant_type' => 'college',
                        'organization_id' => null,
                        'college_id' => $college->id,
                        'status' => EventParticipationScope::STATUS_ACTIVE,
                    ], [
                        'parent_scope_id' => $parentScope->id,
                        'school_year_id' => $event->school_year_id,
                        'department_id' => null,
                        'program_id' => null,
                        'participant_name_snapshot' => trim(($college->code ? "{$college->code} - " : '').$college->name),
                        'ppa_required' => $ppaRequired,
                        'ppa_status' => $ppaRequired ? EventParticipationScope::PPA_DRAFT : EventParticipationScope::PPA_NOT_REQUIRED,
                        'capture_mode' => $captureMode,
                        'analytics_rollup_enabled' => $analyticsRollupEnabled,
                    ]);

                    if (! $participantScope->wasRecentlyCreated) {
                        $skippedExistingCount++;

                        return;
                    }

                    $createdScopes->push($participantScope);
                });
        });

        $createdScopes->each(fn (EventParticipationScope $participantScope): int => $this->notifications
            ->notifyParticipantScopeInvitation($participantScope->fresh(['event.organization', 'organization', 'college', 'delegatedOrganization'])));

        return [
            'created_count' => $createdScopes->count(),
            'skipped_organizer_count' => $skippedOrganizerCount,
            'skipped_existing_count' => $skippedExistingCount,
        ];
    }

    public function syncParticipantScopeFromLinkedEvent(Event $parentEvent, Event $linkedEvent): EventParticipationScope
    {
        $linkedEvent->loadMissing('organization');
        $organization = $linkedEvent->organization;

        if (! $organization) {
            throw new DomainException('The linked event must have an organizer before it can join a parent permit.');
        }

        try {
            return DB::transaction(function () use ($linkedEvent, $organization, $parentEvent): EventParticipationScope {
                $parentScope = $this->ensureOrganizerScope($parentEvent);
                $participantScope = $parentEvent->participationScopes()->firstOrCreate([
                    'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                    'organization_id' => $organization->id,
                    'status' => EventParticipationScope::STATUS_ACTIVE,
                ], [
                    'parent_scope_id' => $parentScope->id,
                    'linked_event_id' => $linkedEvent->id,
                    'school_year_id' => $parentEvent->school_year_id ?: $linkedEvent->school_year_id,
                    'participant_type' => $this->participantTypeForOrganization($organization),
                    'college_id' => $organization->college_id,
                    'department_id' => $linkedEvent->department_id ?: $organization->department_id,
                    'participant_name_snapshot' => $this->participantName($organization, 'Participant'),
                    'ppa_required' => true,
                    'ppa_status' => $this->ppaStatusFromEventStatus($linkedEvent->status),
                    'ppa_softcopy_path' => $linkedEvent->proposal_document_path,
                    'ppa_softcopy_original_name' => $linkedEvent->proposal_document_original_name,
                    'hardcopy_submitted' => (bool) $linkedEvent->hardcopy_submitted,
                    'org_head_signed' => (bool) $linkedEvent->head_organization_signed,
                    'adviser_signed' => (bool) $linkedEvent->adviser_signed,
                    'late_cutoff_min' => $linkedEvent->event_late_cutoff_min,
                    'approved_at' => in_array($linkedEvent->status, [Event::STATUS_APPROVED, Event::STATUS_COMPLETED], true) ? now() : null,
                    'capture_mode' => 'self_scanning',
                    'analytics_rollup_enabled' => true,
                ]);

                if ($participantScope->wasRecentlyCreated || $participantScope->linked_event_id === $linkedEvent->id) {
                    return $participantScope;
                }

                if ($participantScope->linked_event_id) {
                    throw new DomainException('This organization already has an active participant scope linked to another event under the parent permit.');
                }

                $participantScope->update(['linked_event_id' => $linkedEvent->id]);

                return $participantScope;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingLinkedScope = $parentEvent->participationScopes()
                ->active()
                ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                ->where('linked_event_id', $linkedEvent->id)
                ->first();

            if ($existingLinkedScope) {
                throw new DomainException('This linked event already has an active participant scope under the parent permit.', previous: $exception);
            }

            throw $exception;
        }
    }

    public function defaultScopeForAssignment(Event $event, ?User $scannerUser = null): EventParticipationScope
    {
        if ($scannerUser?->organization_id) {
            $participantScope = $event->participationScopes()
                ->approvedForRuntime()
                ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                ->where('organization_id', $scannerUser->organization_id)
                ->first();

            if ($participantScope) {
                return $participantScope;
            }
        }

        return $this->ensureOrganizerScope($event);
    }

    public function runtimeGuard(EventParticipationScope $scope): ?array
    {
        if ($scope->status !== EventParticipationScope::STATUS_ACTIVE) {
            return [
                'status' => 423,
                'reason' => 'participation_scope_inactive',
                'message' => 'This participant scope is no longer active.',
            ];
        }

        if ($scope->ppa_required && $scope->ppa_status !== EventParticipationScope::PPA_APPROVED) {
            return [
                'status' => 423,
                'reason' => 'participant_ppa_locked',
                'message' => 'Scanner access is locked until this participant PPA is approved.',
            ];
        }

        return null;
    }

    public function canManageScope(User $admin, EventParticipationScope $scope): bool
    {
        $adminScope = $this->adminScopes->forUser($admin);
        if (! $adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);

        $scopeSchoolYearId = $scope->school_year_id ?: $scope->event?->school_year_id;
        if (filled($scopeSchoolYearId) && $adminScope->schoolYearId !== $scopeSchoolYearId) {
            return false;
        }

        if ($scope->capture_mode === 'delegated_scanning' && $scope->delegatedOrganization) {
            return $adminScope->canAccessOrganization($scope->delegatedOrganization);
        }

        if (in_array($scope->capture_mode, ['parent_scanning', 'central_gate'], true)) {
            return $adminScope->canAccessEvent($scope->event);
        }

        if ($scope->organization) {
            return $adminScope->canAccessOrganization($scope->organization);
        }

        if ($scope->participant_type === 'department' && $scope->department_id) {
            if ($adminScope->departmentId && $adminScope->departmentId === $scope->department_id) {
                return true;
            }

            if ($scope->college_id && $adminScope->isLsgAdmin()) {
                return $adminScope->collegeId === $scope->college_id;
            }

            return false;
        }

        if ($scope->college_id && $adminScope->isLsgAdmin()) {
            return $adminScope->collegeId === $scope->college_id;
        }

        return $adminScope->canAccessEvent($scope->event);
    }

    public function canViewScope(User $admin, EventParticipationScope $scope): bool
    {
        $adminScope = $this->adminScopes->forUser($admin);
        if (! $adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['event.organization', 'organization']);

        $scopeSchoolYearId = $scope->school_year_id ?: $scope->event?->school_year_id;
        if (filled($scopeSchoolYearId) && $adminScope->schoolYearId !== $scopeSchoolYearId) {
            return false;
        }

        if ($scope->analytics_rollup_enabled && $adminScope->canAccessEvent($scope->event)) {
            return true;
        }

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
        }

        if ($scope->participant_type === 'department' && $scope->department_id) {
            if ($adminScope->departmentId && $adminScope->departmentId === $scope->department_id) {
                return true;
            }

            if ($scope->college_id && $adminScope->isLsgAdmin()) {
                return $adminScope->collegeId === $scope->college_id;
            }
        }

        return $scope->college_id && $adminScope->isLsgAdmin() && $adminScope->collegeId === $scope->college_id;
    }

    public function audienceQuery(EventParticipationScope $scope, bool $requireRuntimeApproval = true): Builder
    {
        $scope->loadMissing(['event.organization', 'organization']);

        if ($requireRuntimeApproval && $this->runtimeGuard($scope)) {
            return User::query()->whereRaw('1 = 0');
        }

        if ($scope->event?->event_domain === 'hr' || $scope->participant_type === 'hr_unit') {
            return User::query()
                ->whereIn('id', function ($query) use ($scope): void {
                    $query->select('user_id')
                        ->from('employee_roster_entries')
                        ->whereNotNull('user_id')
                        ->where('status', 'active')
                        ->when($scope->college_id, fn ($query, $collegeId) => $query->where('college_id', $collegeId));
                });
        }

        $query = User::role('Student');
        $organization = $scope->organization;
        $scopeSchoolYearId = $scope->school_year_id ?: $scope->event?->school_year_id;

        if ($scope->participant_type === 'program' && $scope->program_id) {
            return $query->where('program_id', $scope->program_id);
        }

        if ($scope->participant_type === 'department' && $scope->department_id) {
            return $query->where(function ($query) use ($scope, $scopeSchoolYearId): void {
                $query->where('department_id', $scope->department_id)
                    ->orWhereIn('id', function ($membershipQuery) use ($scope, $scopeSchoolYearId): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('department_id', $scope->department_id)
                            ->where('status', 'active');

                        if ($scopeSchoolYearId) {
                            $membershipQuery->where(function ($sq) use ($scopeSchoolYearId): void {
                                $sq->whereNull('school_year_id')
                                    ->orWhere('school_year_id', $scopeSchoolYearId);
                            });
                        }
                    });
            });
        }

        if ($scope->participant_type === 'college' || ($organization?->type === 'lsg' && $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER)) {
            if (! $scope->college_id) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($query) use ($scope, $scopeSchoolYearId): void {
                $query->where('college_id', $scope->college_id)
                    ->orWhereIn('id', function ($membershipQuery) use ($scope, $scopeSchoolYearId): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('college_id', $scope->college_id)
                            ->where('status', 'active');

                        if ($scopeSchoolYearId) {
                            $membershipQuery->where(function ($sq) use ($scopeSchoolYearId): void {
                                $sq->whereNull('school_year_id')
                                    ->orWhere('school_year_id', $scopeSchoolYearId);
                            });
                        }
                    });
            });
        }

        if ($organization?->type === 'usg' && $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER) {
            return $query;
        }

        if ($scope->organization_id) {
            return $query->where(function ($query) use ($scope, $scopeSchoolYearId): void {
                $query->where('organization_id', $scope->organization_id)
                    ->orWhereIn('id', function ($membershipQuery) use ($scope, $scopeSchoolYearId): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('organization_id', $scope->organization_id)
                            ->where('status', 'active');

                        if ($scopeSchoolYearId) {
                            $membershipQuery->where(function ($sq) use ($scopeSchoolYearId): void {
                                $sq->whereNull('school_year_id')
                                    ->orWhere('school_year_id', $scopeSchoolYearId);
                            });
                        }
                    });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function userBelongsToScope(User $user, EventParticipationScope $scope): bool
    {
        if ($scope->capture_mode === 'delegated_scanning' && $scope->delegated_to_organization_id) {
            $scopeSchoolYearId = $scope->school_year_id ?: $scope->event?->school_year_id;
            if ($user->organization_id === $scope->delegated_to_organization_id) {
                return true;
            }

            return $user->activeOrganizationMemberships()
                ->where('organization_id', $scope->delegated_to_organization_id)
                ->where('status', 'active')
                ->when($scopeSchoolYearId, fn ($query) => $query->where(fn ($sq) => $sq->whereNull('school_year_id')->orWhere('school_year_id', $scopeSchoolYearId)))
                ->exists();
        }

        return $this->audienceQuery($scope, requireRuntimeApproval: false)
            ->whereKey($user->id)
            ->exists();
    }

    public function approvedParticipantScopes(Event $event): Collection
    {
        return $event->participationScopes()
            ->with(['organization', 'college', 'program'])
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->approvedForRuntime()
            ->orderBy('participant_name_snapshot')
            ->get();
    }

    public function scopePayload(?EventParticipationScope $scope): ?array
    {
        if (! $scope) {
            return null;
        }

        $scope->loadMissing(['organization', 'college', 'department', 'program', 'schoolYear']);

        return [
            'id' => $scope->id,
            'name' => $scope->displayName(),
            'school_year_id' => $scope->school_year_id,
            'department_id' => $scope->department_id,
            'participation_role' => $scope->participation_role,
            'participant_type' => $scope->participant_type,
            'capture_mode' => $scope->capture_mode,
            'ppa_status' => $scope->ppa_status,
            'status' => $scope->status,
            'late_cutoff_min' => $scope->late_cutoff_min,
            'organization' => $scope->organization ? [
                'id' => $scope->organization->id,
                'name' => $scope->organization->name,
                'acronym' => $scope->organization->acronym,
                'type' => $scope->organization->type,
            ] : null,
            'department' => $scope->department ? [
                'id' => $scope->department->id,
                'name' => $scope->department->name,
                'code' => $scope->department->code,
            ] : null,
        ];
    }

    public function participantName(?Organization $organization, string $fallback): string
    {
        if (! $organization) {
            return $fallback;
        }

        return trim(($organization->acronym ? "{$organization->acronym} - " : '').$organization->name);
    }

    public function participantTypeForOrganization(?Organization $organization): string
    {
        return match ($organization?->type) {
            'society' => 'society',
            'lsg' => 'college',
            'non_academic_org' => 'non_academic_org',
            'academic_unit' => 'academic_unit',
            'administrative_office' => 'administrative_office',
            'hr_unit' => 'hr_unit',
            default => 'organization',
        };
    }

    public function ppaStatusFromEventStatus(?string $status): string
    {
        return match ($status) {
            Event::STATUS_APPROVED, Event::STATUS_COMPLETED => EventParticipationScope::PPA_APPROVED,
            Event::STATUS_UNDER_REVIEW => EventParticipationScope::PPA_UNDER_REVIEW,
            Event::STATUS_REVISION_REQUESTED => EventParticipationScope::PPA_REVISION_REQUESTED,
            Event::STATUS_SUBMITTED => EventParticipationScope::PPA_SUBMITTED,
            Event::STATUS_REJECTED => EventParticipationScope::PPA_REJECTED,
            default => EventParticipationScope::PPA_DRAFT,
        };
    }
}
