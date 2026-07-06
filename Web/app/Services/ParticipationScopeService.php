<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ParticipationScopeService
{
    public function __construct(private readonly AdminScopeService $adminScopes)
    {
    }

    public function ensureOrganizerScope(Event $event): EventParticipationScope
    {
        $event->loadMissing('organization');

        $existing = $event->participationScopes()
            ->where('participation_role', EventParticipationScope::ROLE_ORGANIZER)
            ->first();

        if ($existing) {
            return $existing;
        }

        $organization = $event->organization;
        $isHrDomain = $event->event_domain === 'hr' || $organization?->type === 'hr_unit';

        return EventParticipationScope::create([
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'participant_type' => $this->participantTypeForOrganization($organization),
            'organization_id' => $organization?->id,
            'college_id' => $organization?->college_id,
            'participant_name_snapshot' => $this->participantName($organization, 'Organizer'),
            'ppa_required' => !$isHrDomain,
            'ppa_status' => $isHrDomain ? EventParticipationScope::PPA_NOT_REQUIRED : $this->ppaStatusFromEventStatus($event->status),
            'ppa_softcopy_path' => $event->proposal_document_path,
            'ppa_softcopy_original_name' => $event->proposal_document_original_name,
            'hardcopy_submitted' => (bool) $event->hardcopy_submitted,
            'org_head_signed' => (bool) $event->head_organization_signed,
            'adviser_signed' => (bool) $event->adviser_signed,
            'approved_at' => in_array($event->status, ['approved', 'completed'], true) ? now() : null,
            'capture_mode' => in_array($organization?->type, ['usg', 'lsg', 'hr_unit'], true) ? 'central_gate' : 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);
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
        if (!$adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);

        if ($scope->capture_mode === 'delegated_scanning' && $scope->delegatedOrganization) {
            return $adminScope->canAccessOrganization($scope->delegatedOrganization);
        }

        if (in_array($scope->capture_mode, ['parent_scanning', 'central_gate'], true)) {
            return $adminScope->canAccessEvent($scope->event);
        }

        if ($scope->organization) {
            return $adminScope->canAccessOrganization($scope->organization);
        }

        if ($scope->college_id && $adminScope->isLsgAdmin()) {
            return $adminScope->collegeId === $scope->college_id;
        }

        return $adminScope->canAccessEvent($scope->event);
    }

    public function canViewScope(User $admin, EventParticipationScope $scope): bool
    {
        $adminScope = $this->adminScopes->forUser($admin);
        if (!$adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['event.organization', 'organization']);

        if ($scope->analytics_rollup_enabled && $adminScope->canAccessEvent($scope->event)) {
            return true;
        }

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
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

        if ($scope->participant_type === 'program' && $scope->program_id) {
            return $query->where('program_id', $scope->program_id);
        }

        if ($scope->participant_type === 'college' || ($organization?->type === 'lsg' && $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER)) {
            if (!$scope->college_id) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($query) use ($scope): void {
                $query->where('college_id', $scope->college_id)
                    ->orWhereIn('id', function ($membershipQuery) use ($scope): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('college_id', $scope->college_id)
                            ->where('status', 'active');
                    });
            });
        }

        if ($organization?->type === 'usg' && $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER) {
            return $query;
        }

        if ($scope->organization_id) {
            return $query->where(function ($query) use ($scope): void {
                $query->where('organization_id', $scope->organization_id)
                    ->orWhereIn('id', function ($membershipQuery) use ($scope): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('organization_id', $scope->organization_id)
                            ->where('status', 'active');
                    });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public function userBelongsToScope(User $user, EventParticipationScope $scope): bool
    {
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
        if (!$scope) {
            return null;
        }

        $scope->loadMissing(['organization', 'college', 'program']);

        return [
            'id' => $scope->id,
            'name' => $scope->displayName(),
            'participation_role' => $scope->participation_role,
            'participant_type' => $scope->participant_type,
            'capture_mode' => $scope->capture_mode,
            'ppa_status' => $scope->ppa_status,
            'status' => $scope->status,
            'organization' => $scope->organization ? [
                'id' => $scope->organization->id,
                'name' => $scope->organization->name,
                'acronym' => $scope->organization->acronym,
                'type' => $scope->organization->type,
            ] : null,
        ];
    }

    public function participantName(?Organization $organization, string $fallback): string
    {
        if (!$organization) {
            return $fallback;
        }

        return trim(($organization->acronym ? "{$organization->acronym} - " : '') . $organization->name);
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
            'approved', 'completed' => EventParticipationScope::PPA_APPROVED,
            'under_review' => EventParticipationScope::PPA_UNDER_REVIEW,
            'submitted' => EventParticipationScope::PPA_SUBMITTED,
            'rejected' => EventParticipationScope::PPA_REJECTED,
            default => EventParticipationScope::PPA_DRAFT,
        };
    }
}
