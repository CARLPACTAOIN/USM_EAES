<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\User;

class ProposalGovernanceService
{
    /**
     * Resolve the active school year.
     */
    public function resolveActiveSchoolYear(): ?SchoolYear
    {
        return SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->first();
    }

    /**
     * Resolve the verified officer roster for the organization and school year.
     */
    public function resolveVerifiedRoster(Organization $organization, ?SchoolYear $schoolYear = null): ?OrganizationOfficerRoster
    {
        $schoolYear ??= $this->resolveActiveSchoolYear();

        if (! $schoolYear) {
            return null;
        }

        return OrganizationOfficerRoster::with(['officers.user'])
            ->where('organization_id', $organization->id)
            ->where('school_year_id', $schoolYear->id)
            ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
            ->latest('verified_at')
            ->first();
    }

    /**
     * Resolve the proponent from verified Secretary and President rosters for the active School Year.
     */
    public function resolveProponent(Organization $organization, ?SchoolYear $schoolYear = null, ?User $actor = null): ?User
    {
        $roster = $this->resolveVerifiedRoster($organization, $schoolYear);

        if ($roster) {
            $activeOfficers = $roster->officers->where('status', OrganizationOfficer::STATUS_ACTIVE);

            // If the actor is a verified secretary or president, they are the proponent
            if ($actor) {
                $isActorOfficer = $activeOfficers->first(function (OrganizationOfficer $officer) use ($actor): bool {
                    return $officer->user_id === $actor->id
                        && in_array($officer->position_key, [OrganizationOfficer::POSITION_SECRETARY, OrganizationOfficer::POSITION_PRESIDENT], true);
                });

                if ($isActorOfficer) {
                    return $actor;
                }
            }

            // Secretary is primary proponent default
            $secretary = $activeOfficers->firstWhere('position_key', OrganizationOfficer::POSITION_SECRETARY);
            if ($secretary?->user) {
                return $secretary->user;
            }

            // Fallback to president
            $president = $activeOfficers->firstWhere('position_key', OrganizationOfficer::POSITION_PRESIDENT);
            if ($president?->user) {
                return $president->user;
            }
        }

        // Fallback for organizations without verified rosters
        return $actor;
    }

    /**
     * Resolve the Organization Head (verified President) for the active School Year.
     */
    public function resolveOrganizationHead(Organization $organization, ?SchoolYear $schoolYear = null): ?User
    {
        $roster = $this->resolveVerifiedRoster($organization, $schoolYear);

        if ($roster) {
            $president = $roster->officers
                ->where('status', OrganizationOfficer::STATUS_ACTIVE)
                ->firstWhere('position_key', OrganizationOfficer::POSITION_PRESIDENT);

            if ($president?->user) {
                return $president->user;
            }
        }

        // Fallback: active primary admin assignment for the organization
        $assignment = AdminAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('position_title', 'like', '%president%');
            })
            ->latest('is_primary_admin')
            ->first();

        return $assignment?->user;
    }

    /**
     * Dynamically resolve Department Chairperson from the proposing organization's Department.
     */
    public function resolveDepartmentChairperson(Organization $organization): ?User
    {
        $department = $organization->department;

        if (! $department) {
            $program = $organization->programs()->first();
            $department = $program?->department;
        }

        if (! $department) {
            return null;
        }

        // 1. Check active AdminAssignment for this department
        $assignment = AdminAssignment::query()
            ->where('department_id', $department->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereIn('role_name', ['Department Chairperson', 'dept_chair', 'Department Chair', 'Academic Unit Admin'])
                    ->orWhere('position_title', 'like', '%chair%')
                    ->orWhere('position_title', 'like', '%chairperson%');
            })
            ->orderByDesc('is_primary_admin')
            ->latest('approved_at')
            ->first();

        if ($assignment?->user) {
            return $assignment->user;
        }

        // 2. Check User with department_id and chairperson role
        return User::query()
            ->where('department_id', $department->id)
            ->whereHas('roles', function ($query): void {
                $query->whereIn('name', ['Department Chairperson', 'dept_chair', 'Department Chair']);
            })
            ->first();
    }

    /**
     * Dynamically resolve College Dean from the proposing organization's College.
     */
    public function resolveCollegeDean(Organization $organization): ?User
    {
        $college = $organization->resolvedCollege();

        if (! $college) {
            return null;
        }

        // 1. Check active AdminAssignment for this college
        $assignment = AdminAssignment::query()
            ->where('college_id', $college->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereIn('role_name', ['College Dean', 'college_dean', 'Dean', 'LSG Admin'])
                    ->orWhere('position_title', 'like', '%dean%');
            })
            ->orderByDesc('is_primary_admin')
            ->latest('approved_at')
            ->first();

        if ($assignment?->user) {
            return $assignment->user;
        }

        // 2. Check User with college_id and dean role
        return User::query()
            ->where('college_id', $college->id)
            ->whereHas('roles', function ($query): void {
                $query->whereIn('name', ['College Dean', 'college_dean', 'Dean']);
            })
            ->first();
    }

    /**
     * Resolve Program for the organization or proponent.
     */
    public function resolveProgram(Organization $organization, ?User $actor = null): ?Program
    {
        return $organization->programs()->first() ?? $actor?->program;
    }

    /**
     * Resolve the complete governance context and snapshots.
     *
     * @return array{
     *     school_year: ?SchoolYear,
     *     school_year_id: ?string,
     *     organization: Organization,
     *     department: ?Department,
     *     college: ?College,
     *     program: ?Program,
     *     proponent: ?User,
     *     organization_head: ?User,
     *     department_chairperson: ?User,
     *     college_dean: ?User,
     *     snapshots: array<string, mixed>
     * }
     */
    public function resolveGovernanceContext(Organization $organization, ?User $actor = null, ?SchoolYear $schoolYear = null): array
    {
        $schoolYear ??= $this->resolveActiveSchoolYear();
        $department = $organization->department ?? $organization->programs()->first()?->department;
        $college = $organization->resolvedCollege();
        $program = $this->resolveProgram($organization, $actor);

        $proponent = $this->resolveProponent($organization, $schoolYear, $actor);
        $orgHead = $this->resolveOrganizationHead($organization, $schoolYear);
        $deptChair = $this->resolveDepartmentChairperson($organization);
        $collegeDean = $this->resolveCollegeDean($organization);

        $proponentName = $proponent?->name ?? $actor?->name;

        $snapshots = [
            'school_year_id' => $schoolYear?->id,
            'college_name_snapshot' => $college?->name,
            'department_name_snapshot' => $department?->name,
            'program_name_snapshot' => $program?->name,
            'organization_name_snapshot' => $organization->name,
            'proponent_name_snapshot' => $proponentName,
            'proponent_user_id' => $proponent?->id,
            'org_head_user_id' => $orgHead?->id,
            'dept_chair_user_id' => $deptChair?->id,
            'college_dean_user_id' => $collegeDean?->id,
            'metadata' => [
                'school_year_label' => $schoolYear?->label,
                'college_code' => $college?->code,
                'department_code' => $department?->code,
                'program_code' => $program?->code,
                'organization_acronym' => $organization->acronym,
                'proponent_email' => $proponent?->email ?? $actor?->email,
                'org_head_email' => $orgHead?->email,
                'dept_chair_email' => $deptChair?->email,
                'college_dean_email' => $collegeDean?->email,
            ],
        ];

        return [
            'school_year' => $schoolYear,
            'school_year_id' => $schoolYear?->id,
            'organization' => $organization,
            'department' => $department,
            'college' => $college,
            'program' => $program,
            'proponent' => $proponent,
            'organization_head' => $orgHead,
            'department_chairperson' => $deptChair,
            'college_dean' => $collegeDean,
            'snapshots' => $snapshots,
        ];
    }
}
