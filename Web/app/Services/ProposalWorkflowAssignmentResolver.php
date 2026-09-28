<?php

namespace App\Services;

use App\Exceptions\WorkflowAssignmentResolutionException;
use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\ProposalProcessStep;
use App\Models\SchoolYear;
use App\Models\User;

class ProposalWorkflowAssignmentResolver
{
    public function __construct(private readonly ProposalGovernanceService $governance) {}

    /**
     * @return array{
     *     assigned_user_id: ?string,
     *     assigned_role: ?string,
     *     assigned_scope: ?string,
     *     metadata: array<string, mixed>
     * }
     */
    public function resolve(
        Event $event,
        ProposalProcessStep $step,
        User $actor,
        ?EventParticipationScope $subject = null,
    ): array {
        $event->loadMissing([
            'schoolYear',
            'organization.department.college',
            'department',
            'venue',
            'proponent',
            'organizationHead',
            'departmentChairperson',
            'collegeDean',
        ]);
        $subject?->loadMissing([
            'schoolYear',
            'organization.department.college',
            'department',
            'college',
        ]);

        $config = $step->condition_config ?? [];
        $roleKey = $this->normalizeRoleKey($config['assigned_role'] ?? null);
        $scopeKey = $this->normalizeScopeKey($config['assigned_scope'] ?? null);

        if ($roleKey === null) {
            return [
                'assigned_user_id' => null,
                'assigned_role' => null,
                'assigned_scope' => $scopeKey,
                'metadata' => [
                    'assignment' => [
                        'role_key' => null,
                        'scope_key' => $scopeKey,
                        'scope' => $this->scopeSnapshot($event, $subject),
                        'resolved_at' => now()->toIso8601String(),
                    ],
                ],
            ];
        }

        $resolved = $this->resolveUser($event, $roleKey, $actor, $subject);

        if (! $resolved['user'] && $step->is_required && ! $this->allowsMissingRequiredAssignee($step, $roleKey, $config)) {
            $roleLabel = $this->roleLabel($roleKey);

            throw new WorkflowAssignmentResolutionException(
                "{$roleLabel} could not be resolved for workflow step {$step->title}.",
                $roleKey,
                $roleLabel,
                $step->step_key,
                $step->title,
            );
        }

        /** @var User|null $user */
        $user = $resolved['user'];

        return [
            'assigned_user_id' => $user?->id,
            'assigned_role' => $roleKey,
            'assigned_scope' => $scopeKey,
            'metadata' => [
                'assignment' => [
                    'role_key' => $roleKey,
                    'role_label' => $this->roleLabel($roleKey),
                    'scope_key' => $scopeKey,
                    'scope' => $this->scopeSnapshot($event, $subject),
                    'source' => $resolved['source'],
                    'assigned_user_snapshot' => $user ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ] : null,
                    'resolved_at' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function allowsMissingRequiredAssignee(ProposalProcessStep $step, string $roleKey, array $config): bool
    {
        if ((bool) data_get($config, 'allow_unassigned_required', false)) {
            return true;
        }

        return $roleKey === 'osa'
            && $step->step_key === 'participant_osa_approval'
            && ! User::role('Super Admin (OSA)')->exists();
    }

    /**
     * @return array{user: ?User, source: array<string, mixed>}
     */
    private function resolveUser(
        Event $event,
        string $roleKey,
        User $actor,
        ?EventParticipationScope $subject,
    ): array {
        $organization = $this->subjectOrganization($event, $subject);
        $schoolYear = $this->subjectSchoolYear($event, $subject);

        return match ($roleKey) {
            'proponent' => $subject
                ? $this->fromUser(
                    $this->governance->resolveProponent($organization, $schoolYear, $actor),
                    'governance_resolver',
                    'proponent',
                )
                : $this->fromEventSnapshot(
                    $event->proponent,
                    'event_snapshot',
                    'proponent_user_id',
                ) ?? $this->fromUser(
                    $this->governance->resolveProponent($organization, $schoolYear, $actor),
                    'governance_resolver',
                    'proponent',
                ),
            'organization_head' => $subject
                ? $this->fromUser(
                    $this->governance->resolveOrganizationHead($organization, $schoolYear),
                    'governance_resolver',
                    'organization_head',
                )
                : $this->fromEventSnapshot(
                    $event->organizationHead,
                    'event_snapshot',
                    'org_head_user_id',
                ) ?? $this->fromUser(
                    $this->governance->resolveOrganizationHead($organization, $schoolYear),
                    'governance_resolver',
                    'organization_head',
                ),
            'adviser' => $this->resolveAdviser($event, $subject),
            'dept_chair' => $subject
                ? $this->fromUser(
                    $this->governance->resolveDepartmentChairperson($organization),
                    'governance_resolver',
                    'dept_chair',
                )
                : $this->fromEventSnapshot(
                    $event->departmentChairperson,
                    'event_snapshot',
                    'dept_chair_user_id',
                ) ?? $this->fromUser(
                    $this->governance->resolveDepartmentChairperson($organization),
                    'governance_resolver',
                    'dept_chair',
                ),
            'college_dean' => $subject
                ? $this->fromUser(
                    $this->governance->resolveCollegeDean($organization),
                    'governance_resolver',
                    'college_dean',
                )
                : $this->fromEventSnapshot(
                    $event->collegeDean,
                    'event_snapshot',
                    'college_dean_user_id',
                ) ?? $this->fromUser(
                    $this->governance->resolveCollegeDean($organization),
                    'governance_resolver',
                    'college_dean',
                ),
            'chief_security' => $this->resolveInstitutionalAssignment(
                $event,
                ['Chief Security', 'Office of Security', 'Security Admin'],
                'chief_security',
                $subject,
            ) ?? $this->fromUser(User::role('Chief Security')->first(), 'spatie_role', 'Chief Security'),
            'osa' => $this->resolveInstitutionalAssignment(
                $event,
                ['Super Admin (OSA)'],
                'osa',
                $subject,
            ) ?? $this->fromUser(User::role('Super Admin (OSA)')->first(), 'spatie_role', 'Super Admin (OSA)'),
            'venue_admin' => $this->resolveInstitutionalAssignment(
                $event,
                ['Venue Custodian', 'Venue Admin', 'Campus Facilities Office'],
                'venue_admin',
                $subject,
            ),
            default => $this->resolveInstitutionalAssignment($event, [$this->roleLabel($roleKey)], $roleKey, $subject),
        };
    }

    /**
     * @return array{user: ?User, source: array<string, mixed>}
     */
    private function resolveAdviser(Event $event, ?EventParticipationScope $subject): array
    {
        $roster = $this->governance->resolveVerifiedRoster(
            $this->subjectOrganization($event, $subject),
            $this->subjectSchoolYear($event, $subject),
        );
        $officer = $roster?->officers
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->firstWhere('position_key', OrganizationOfficer::POSITION_ADVISER);

        return [
            'user' => $officer?->user,
            'source' => [
                'type' => 'verified_officer_roster',
                'id' => $officer?->id,
                'roster_id' => $roster?->id,
            ],
        ];
    }

    /**
     * @param  list<string>  $roleNames
     * @return array{user: ?User, source: array<string, mixed>}|null
     */
    private function resolveInstitutionalAssignment(
        Event $event,
        array $roleNames,
        string $roleKey,
        ?EventParticipationScope $subject,
    ): ?array {
        $schoolYear = $this->subjectSchoolYear($event, $subject);
        $department = $this->subjectDepartment($event, $subject);
        $college = $this->subjectCollege($event, $subject);

        $assignment = AdminAssignment::query()
            ->with('user')
            ->currentlyEffective()
            ->whereIn('role_name', $roleNames)
            ->where(function ($query) use ($schoolYear): void {
                $query->whereNull('school_year_id');

                if ($schoolYear?->id) {
                    $query->orWhere('school_year_id', $schoolYear->id);
                }
            })
            ->when($roleKey === 'dept_chair', function ($query) use ($department): void {
                $query->where('department_id', $department?->id);
            })
            ->when($roleKey === 'college_dean', function ($query) use ($college): void {
                $query->where('college_id', $college?->id);
            })
            ->orderByDesc('is_primary_admin')
            ->latest('approved_at')
            ->first();

        if (! $assignment) {
            return null;
        }

        return [
            'user' => $assignment->user,
            'source' => [
                'type' => 'admin_assignment',
                'id' => $assignment->id,
                'role_name' => $assignment->role_name,
            ],
        ];
    }

    /**
     * @return array{user: ?User, source: array<string, mixed>}|null
     */
    private function fromEventSnapshot(?User $user, string $type, string $field): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'user' => $user,
            'source' => [
                'type' => $type,
                'field' => $field,
            ],
        ];
    }

    /**
     * @return array{user: ?User, source: array<string, mixed>}
     */
    private function fromUser(?User $user, string $type, string $label): array
    {
        return [
            'user' => $user,
            'source' => [
                'type' => $type,
                'label' => $label,
            ],
        ];
    }

    private function subjectOrganization(Event $event, ?EventParticipationScope $subject): Organization
    {
        return $subject?->organization ?: $event->organization;
    }

    private function subjectSchoolYear(Event $event, ?EventParticipationScope $subject): ?SchoolYear
    {
        return $subject?->schoolYear ?: $event->schoolYear;
    }

    private function subjectDepartment(Event $event, ?EventParticipationScope $subject): ?Department
    {
        return $subject?->department
            ?: $subject?->organization?->department
            ?: $event->department
            ?: $event->organization?->department;
    }

    private function subjectCollege(Event $event, ?EventParticipationScope $subject): ?College
    {
        return $subject?->college
            ?: $subject?->organization?->resolvedCollege()
            ?: $event->organization?->resolvedCollege();
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeSnapshot(Event $event, ?EventParticipationScope $subject): array
    {
        $organization = $this->subjectOrganization($event, $subject);
        $schoolYear = $this->subjectSchoolYear($event, $subject);
        $college = $this->subjectCollege($event, $subject);
        $department = $this->subjectDepartment($event, $subject);
        $isParticipant = $subject !== null;

        return [
            'school_year_id' => $schoolYear?->id,
            'school_year_label' => $schoolYear?->label ?? ($isParticipant ? null : data_get($event->governance_snapshots, 'metadata.school_year_label')),
            'organization_id' => $organization?->id,
            'organization_name' => $isParticipant
                ? ($subject->participant_name_snapshot ?: $organization?->name)
                : ($event->organization_name_snapshot ?? $organization?->name),
            'organization_type' => $organization?->type,
            'college_id' => $college?->id,
            'college_name' => $isParticipant ? $college?->name : ($event->college_name_snapshot ?? $college?->name),
            'department_id' => $department?->id,
            'department_name' => $isParticipant ? $department?->name : ($event->department_name_snapshot ?? $department?->name),
            'venue_id' => $event->venue_id,
            'venue_name' => $event->venue?->displayName(),
            'event_domain' => $event->event_domain,
            'proposal_category' => $event->proposal_category,
            'location_type' => $event->location_type,
        ];
    }

    private function normalizeRoleKey(?string $roleKey): ?string
    {
        if (! $roleKey) {
            return null;
        }

        $normalized = str($roleKey)->lower()->replace([' ', '-'], '_')->toString();

        return match ($normalized) {
            'org_head', 'organization_president', 'president' => 'organization_head',
            'department_chair', 'department_chairperson', 'chair', 'chairperson' => 'dept_chair',
            'dean' => 'college_dean',
            'security', 'office_of_security' => 'chief_security',
            'osa_admin', 'super_admin_osa' => 'osa',
            'org_adviser', 'faculty_adviser' => 'adviser',
            default => $normalized,
        };
    }

    private function normalizeScopeKey(?string $scopeKey): ?string
    {
        return $scopeKey ? str($scopeKey)->lower()->replace([' ', '-'], '_')->toString() : null;
    }

    private function roleLabel(string $roleKey): string
    {
        return match ($roleKey) {
            'proponent' => 'Proponent',
            'organization_head' => 'Organization Head',
            'adviser' => 'Adviser',
            'dept_chair' => 'Department Chairperson',
            'college_dean' => 'College Dean',
            'chief_security' => 'Chief Security',
            'osa' => 'OSA',
            'venue_admin' => 'Venue Custodian',
            default => str($roleKey)->replace('_', ' ')->title()->toString(),
        };
    }
}
