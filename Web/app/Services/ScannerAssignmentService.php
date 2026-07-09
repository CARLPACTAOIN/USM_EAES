<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\ScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScannerAssignmentService
{
    public function __construct(
        private readonly EventLifecycleService $lifecycle,
        private readonly ScannerSessionService $scannerSessions,
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes
    ) {
    }

    public function assign(
        Event $event,
        User $scannerUser,
        User $assignedBy,
        ?string $label = null,
        ?int $maxDevices = 1,
        ?string $notes = null,
        ?EventParticipationScope $participationScope = null
    ): EventScannerAssignment {
        $participationScope ??= $this->participationScopes->defaultScopeForAssignment($event, $scannerUser);
        $participationScope->loadMissing('event');

        if ($participationScope->event_id !== $event->id) {
            throw ValidationException::withMessages([
                'event_participation_scope_id' => 'Selected attendance scope does not belong to this event.',
            ]);
        }

        if (!$this->participationScopes->canManageScope($assignedBy, $participationScope)) {
            throw ValidationException::withMessages([
                'event_participation_scope_id' => 'You cannot assign scanners for this attendance scope.',
            ]);
        }

        if ($guard = $this->participationScopes->runtimeGuard($participationScope)) {
            throw ValidationException::withMessages([
                'event_participation_scope_id' => $guard['message'],
            ]);
        }

        $this->assertEligibleScanner($scannerUser, $participationScope);
        if (!$this->participationScopes->userBelongsToScope($scannerUser, $participationScope)) {
            throw ValidationException::withMessages([
                'scanner_user_id' => 'Select a scanner account inside the selected attendance scope.',
            ]);
        }

        $this->assertNoDuplicateActiveAssignment($event, $scannerUser, $label, $participationScope);

        return DB::transaction(function () use ($event, $scannerUser, $assignedBy, $label, $maxDevices, $notes, $participationScope): EventScannerAssignment {
            $assignment = EventScannerAssignment::create([
                'event_id' => $event->id,
                'event_participation_scope_id' => $participationScope->id,
                'audience_scope_type' => $participationScope->participant_type,
                'scanner_user_id' => $scannerUser->id,
                'assigned_by_user_id' => $assignedBy->id,
                'label' => $label,
                'status' => EventScannerAssignment::STATUS_ACTIVE,
                'expires_at' => $this->lifecycle->syncGraceEndsAt($event),
                'max_devices' => max(1, (int) ($maxDevices ?? 1)),
                'notes' => $notes,
            ]);

            AuditLog::create([
                'target_id' => $assignment->id,
                'admin_id' => $assignedBy->id,
                'action' => 'event-scanner-assignment-created',
                'details' => [
                    'event_id' => $event->id,
                    'event_participation_scope_id' => $participationScope->id,
                    'scanner_user_id' => $scannerUser->id,
                    'label' => $label,
                    'max_devices' => $assignment->max_devices,
                ],
            ]);

            return $assignment;
        });
    }

    public function revoke(EventScannerAssignment $assignment, User $revokedBy, ?string $reason = null): void
    {
        DB::transaction(function () use ($assignment, $revokedBy, $reason): void {
            $assignment->refresh();

            if ($assignment->status !== EventScannerAssignment::STATUS_REVOKED) {
                $assignment->markRevoked($revokedBy, $reason);
            }

            $assignment->scannerSessions()
                ->where('status', ScannerSession::STATUS_ACTIVE)
                ->get()
                ->each(fn (ScannerSession $session) => $this->scannerSessions->revoke(
                    $session,
                    $revokedBy,
                    $reason ?: 'Scanner assignment revoked.'
                ));

            AuditLog::create([
                'target_id' => $assignment->id,
                'admin_id' => $revokedBy->id,
                'action' => 'event-scanner-assignment-revoked',
                'details' => [
                    'event_id' => $assignment->event_id,
                    'scanner_user_id' => $assignment->scanner_user_id,
                    'reason' => $reason,
                ],
            ]);
        });
    }

    public function listForEvent(Event $event): Collection
    {
        return $event->scannerAssignments()
            ->with(['participationScope.organization', 'scannerUser.organization', 'assignedBy', 'revokedBy', 'scannerSessions'])
            ->latest()
            ->get();
    }

    public function assignmentsForUser(User $user): Collection
    {
        return $user->scannerAssignments()
            ->with(['participationScope.organization', 'event.organization', 'event.eventDays', 'scannerSessions'])
            ->active()
            ->latest()
            ->get();
    }

    public function eligibleUsers(
        ?string $search = null,
        int $limit = 50,
        ?User $admin = null,
        ?EventParticipationScope $participationScope = null
    ): Collection
    {
        return $this->eligibleUsersQuery($admin, $search, $participationScope)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function candidatePayload(User $user): array
    {
        $user->loadMissing(['organization', 'activeOrganizationMemberships.organization', 'employeeRosterEntry']);

        $identity = $user->student_id_number
            ?: $user->employeeRosterEntry?->employee_id
            ?: $user->email;
        $scopeLabel = $this->scannerScopeLabel($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'studentId' => $identity,
            'organization' => $scopeLabel,
            'label' => trim($user->name . ' · ' . $identity . ' · ' . $scopeLabel),
        ];
    }

    public function canAssignScanner(User $admin, User $scannerUser, ?EventParticipationScope $participationScope = null): bool
    {
        $eligible = $this->eligibleUsersQuery(
            admin: $admin,
            participationScope: $participationScope
        )
            ->whereKey($scannerUser->id)
            ->exists();

        if (!$eligible) {
            return false;
        }

        return !$participationScope || $this->participationScopes->userBelongsToScope($scannerUser, $participationScope);
    }

    private function eligibleUsersQuery(
        ?User $admin = null,
        ?string $search = null,
        ?EventParticipationScope $participationScope = null
    ): Builder
    {
        $domain = config('services.eaes.allowed_email_domain', 'usm.edu.ph');

        return User::query()
            ->with(['organization', 'college', 'program', 'activeOrganizationMemberships.organization', 'employeeRosterEntry'])
            ->where('email', 'like', '%@' . $domain)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $studentQuery): void {
                    $studentQuery
                        ->whereNotNull('college_id')
                        ->whereNotNull('program_id')
                        ->whereNotNull('student_id_number')
                        ->whereNotNull('qr_code_value')
                        ->where(function (Builder $scopeQuery): void {
                            $scopeQuery->whereNotNull('organization_id')
                                ->orWhereHas('activeOrganizationMemberships', function (Builder $membershipQuery): void {
                                    $membershipQuery->whereNotNull('organization_id');
                                });
                        });
                })->orWhereHas('employeeRosterEntry', function (Builder $employeeQuery): void {
                    $employeeQuery
                        ->where('status', 'active')
                        ->where(function (Builder $qrQuery): void {
                            $qrQuery->whereNotNull('qr_code_value')
                                ->orWhereNotNull('employee_id');
                        });
                });
            })
            ->when($search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('student_id_number', 'like', "%{$search}%")
                        ->orWhereHas('activeOrganizationMemberships.organization', function (Builder $organizationQuery) use ($search): void {
                            $organizationQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('acronym', 'like', "%{$search}%");
                        })
                        ->orWhereHas('employeeRosterEntry', function (Builder $employeeQuery) use ($search): void {
                            $employeeQuery->where('employee_id', 'like', "%{$search}%")
                                ->orWhere('office_department', 'like', "%{$search}%");
                        });
                });
            })
            ->when($participationScope, fn (Builder $query, EventParticipationScope $scope) => $this->applyParticipationScope($query, $scope))
            ->when($admin, fn (Builder $query, User $admin) => $this->applyAdminScope($query, $admin));
    }

    private function applyParticipationScope(Builder $query, EventParticipationScope $participationScope): void
    {
        $audienceQuery = $this->participationScopes
            ->audienceQuery($participationScope, requireRuntimeApproval: false)
            ->select('users.id');

        $query->whereIn('users.id', $audienceQuery);
    }

    private function applyAdminScope(Builder $query, User $admin): void
    {
        $scope = $this->adminScopes->forUser($admin);

        if (!$scope) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if ($scope->isUsgAdmin()) {
            $query->where(function (Builder $query) use ($scope): void {
                $query->where('organization_id', $scope->organizationId)
                    ->orWhereHas('organization', fn (Builder $query) => $query->where('type', 'usg'))
                    ->orWhereHas('activeOrganizationMemberships.organization', function (Builder $organizationQuery) use ($scope): void {
                        $organizationQuery
                            ->where('type', 'usg')
                            ->orWhere('id', $scope->organizationId);
                    });
            });
            return;
        }

        if ($scope->isLsgAdmin()) {
            if (!$scope->collegeId) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->where(function (Builder $query) use ($scope): void {
                $query->where('college_id', $scope->collegeId)
                    ->orWhereHas('activeOrganizationMemberships', function (Builder $membershipQuery) use ($scope): void {
                        $membershipQuery->where('college_id', $scope->collegeId);
                    });
            });
            return;
        }

        if ($scope->isHrAdmin()) {
            $query->whereHas('employeeRosterEntry', fn (Builder $employeeQuery) => $employeeQuery->where('status', 'active'));
            return;
        }

        if (!$scope->organizationId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function (Builder $query) use ($scope): void {
            $query->where('organization_id', $scope->organizationId)
                ->orWhereHas('activeOrganizationMemberships', function (Builder $membershipQuery) use ($scope): void {
                    $membershipQuery->where('organization_id', $scope->organizationId);
                });
        });
    }

    public function isEligibleScanner(User $user, ?EventParticipationScope $scope = null): bool
    {
        $domain = config('services.eaes.allowed_email_domain', 'usm.edu.ph');

        if (!str_ends_with(strtolower($user->email), '@' . strtolower($domain))) {
            return false;
        }

        if ($scope && ($scope->event?->event_domain === 'hr' || $scope->participant_type === 'hr_unit')) {
            $entry = $user->employeeRosterEntry;

            return $entry
                && $entry->status === 'active'
                && (filled($entry->qr_code_value) || filled($entry->employee_id));
        }

        return $this->hasActiveStudentOrganizationProfile($user)
            && filled($user->college_id)
            && filled($user->program_id)
            && filled($user->student_id_number)
            && filled($user->qr_code_value);
    }

    public function activeAssignmentFor(User $user, Event $event): ?EventScannerAssignment
    {
        return EventScannerAssignment::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $user->id)
            ->active()
            ->first();
    }

    private function assertEligibleScanner(User $user, ?EventParticipationScope $scope = null): void
    {
        $scope?->loadMissing('event');

        if (!$this->isEligibleScanner($user, $scope)) {
            throw ValidationException::withMessages([
                'scanner_user_id' => 'Select an institutional account with a completed scanner-ready profile for this attendance scope.',
            ]);
        }
    }

    private function hasActiveStudentOrganizationProfile(User $user): bool
    {
        if (filled($user->organization_id) && filled($user->college_id) && filled($user->program_id)) {
            return true;
        }

        $memberships = $user->relationLoaded('activeOrganizationMemberships')
            ? $user->activeOrganizationMemberships
            : $user->activeOrganizationMemberships()->get();

        return $memberships->contains(fn ($membership) => filled($membership->organization_id)
            && (filled($user->college_id) || filled($membership->college_id))
            && (filled($user->program_id) || filled($membership->program_id)));
    }

    private function scannerScopeLabel(User $user): string
    {
        if ($user->organization) {
            return $user->organization->acronym ?: $user->organization->name;
        }

        $membershipLabel = $user->activeOrganizationMemberships
            ->map(fn ($membership) => $membership->organization?->acronym ?: $membership->organization?->name)
            ->filter()
            ->unique()
            ->join(', ');

        if ($membershipLabel !== '') {
            return $membershipLabel;
        }

        return $user->employeeRosterEntry?->office_department ?: 'No scope';
    }

    private function assertNoDuplicateActiveAssignment(Event $event, User $scannerUser, ?string $label, EventParticipationScope $participationScope): void
    {
        $exists = $event->scannerAssignments()
            ->active()
            ->where('event_participation_scope_id', $participationScope->id)
            ->where('scanner_user_id', $scannerUser->id)
            ->where(function ($query) use ($label): void {
                $label = trim((string) $label);
                if ($label === '') {
                    $query->whereNull('label')->orWhere('label', '');
                    return;
                }

                $query->where('label', $label);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'scanner_user_id' => 'This student already has an active scanner assignment for this event and label.',
            ]);
        }
    }
}
