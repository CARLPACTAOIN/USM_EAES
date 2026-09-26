<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Organization;
use App\Models\OsaAuthorityOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OsaAuthorityOverrideService
{
    public function __construct(private readonly AdminAssignmentService $assignments) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, User $actor): OsaAuthorityOverride
    {
        return DB::transaction(function () use ($data, $actor): OsaAuthorityOverride {
            $assignment = $this->sourceAssignment($data);
            $scope = $this->scopeFromData($data, $assignment);
            $termStart = $data['term_start'] ?? (isset($data['starts_at']) ? Carbon::parse($data['starts_at'])->toDateString() : $assignment?->term_start?->toDateString());
            $termEnd = $data['term_end'] ?? (isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->toDateString() : $assignment?->term_end?->toDateString());

            $override = OsaAuthorityOverride::create([
                'action' => $data['action'],
                'status' => OsaAuthorityOverride::STATUS_ACTIVE,
                'target_user_id' => $data['target_user_id'] ?? $assignment?->user_id,
                'role_name' => $data['role_name'] ?? $assignment?->role_name,
                'organization_id' => $scope['organization']?->id,
                'college_id' => $scope['college']?->id,
                'department_id' => $scope['department_id'],
                'school_year_id' => $scope['school_year_id'],
                'academic_year' => $scope['academic_year'],
                'term_start' => $termStart,
                'term_end' => $termEnd,
                'position_title' => $data['position_title'] ?? $assignment?->position_title,
                'reason' => $data['reason'],
                'starts_at' => $data['starts_at'] ?? now(),
                'expires_at' => $data['expires_at'] ?? null,
                'source_assignment_id' => $assignment?->id,
                'actor_id' => $actor->id,
                'acted_at' => now(),
            ]);

            $projectedAssignment = match ($override->action) {
                OsaAuthorityOverride::ACTION_GRANT => $this->grant($override, $data, $actor, replace: false),
                OsaAuthorityOverride::ACTION_REPLACE => $this->grant($override, $data, $actor, replace: true),
                OsaAuthorityOverride::ACTION_REVOKE => $this->revoke($override, $assignment, $actor),
                OsaAuthorityOverride::ACTION_LOCK => null,
            };

            if ($projectedAssignment) {
                $override->update(['projected_assignment_id' => $projectedAssignment->id]);
                $override->setRelation('projectedAssignment', $projectedAssignment);
            }

            AuditLog::create([
                'target_id' => $override->id,
                'admin_id' => $actor->id,
                'action' => 'execute-osa-authority-override',
                'details' => [
                    'action' => $override->action,
                    'status' => $override->status,
                    'user_id' => $override->target_user_id,
                    'role_name' => $override->role_name,
                    'organization_id' => $override->organization_id,
                    'college_id' => $override->college_id,
                    'department_id' => $override->department_id,
                    'school_year_id' => $override->school_year_id,
                    'academic_year' => $override->academic_year,
                    'assignment_id' => $projectedAssignment?->id,
                    'source_assignment_id' => $override->source_assignment_id,
                    'reason' => $override->reason,
                ],
            ]);

            return $override->fresh(['targetUser', 'organization', 'college', 'schoolYear', 'projectedAssignment']);
        });
    }

    private function grant(OsaAuthorityOverride $override, array $data, User $actor, bool $replace): AdminAssignment
    {
        $target = User::findOrFail($override->target_user_id);

        if ($replace) {
            $this->revokeActivePrimaryAssignmentsInScope($override, $actor);
        } elseif (($data['is_primary_admin'] ?? true) && $this->activePrimaryAssignmentExists($override)) {
            throw ValidationException::withMessages([
                'is_primary_admin' => 'An active primary admin already exists for this role and scope. Use a replace override to supersede it.',
            ]);
        }

        $assignment = AdminAssignment::create([
            'user_id' => $target->id,
            'role_name' => $override->role_name,
            'organization_id' => $override->organization_id,
            'college_id' => $override->college_id,
            'department_id' => $override->department_id,
            'school_year_id' => $override->school_year_id,
            'academic_year' => $override->academic_year,
            'term_start' => $override->term_start,
            'term_end' => $override->term_end,
            'position_title' => $override->position_title,
            'source_type' => AdminAssignment::SOURCE_OSA_OVERRIDE,
            'source_id' => $override->id,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => (bool) ($data['is_primary_admin'] ?? true),
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        $this->assignments->syncUserProjection($target->fresh());

        return $assignment;
    }

    private function revoke(OsaAuthorityOverride $override, ?AdminAssignment $assignment, User $actor): ?AdminAssignment
    {
        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Select the active assignment to revoke.',
            ]);
        }

        if ($assignment->status !== AdminAssignment::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Only active assignments can be revoked by an override.',
            ]);
        }

        $assignment->update([
            'status' => AdminAssignment::STATUS_REVOKED,
            'revoked_by' => $actor->id,
            'revoked_at' => now(),
            'revocation_reason' => $override->reason,
        ]);

        $this->assignments->syncUserProjection($assignment->user->fresh());

        return $assignment;
    }

    private function revokeActivePrimaryAssignmentsInScope(OsaAuthorityOverride $override, User $actor): void
    {
        $assignments = $this->activePrimaryAssignmentsInScope($override)->get();

        foreach ($assignments as $assignment) {
            $assignment->update([
                'status' => AdminAssignment::STATUS_REVOKED,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' => $override->reason,
            ]);

            $this->assignments->syncUserProjection($assignment->user->fresh());
        }
    }

    private function activePrimaryAssignmentExists(OsaAuthorityOverride $override): bool
    {
        return $this->activePrimaryAssignmentsInScope($override)->exists();
    }

    private function activePrimaryAssignmentsInScope(OsaAuthorityOverride $override): Builder
    {
        return AdminAssignment::query()
            ->where('role_name', $override->role_name)
            ->where('academic_year', $override->academic_year)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('is_primary_admin', true)
            ->where('organization_id', $override->organization_id)
            ->where('college_id', $override->college_id)
            ->where('department_id', $override->department_id)
            ->where('school_year_id', $override->school_year_id);
    }

    private function sourceAssignment(array $data): ?AdminAssignment
    {
        if (empty($data['assignment_id'])) {
            return null;
        }

        return AdminAssignment::with(['organization', 'college'])->findOrFail($data['assignment_id']);
    }

    /**
     * @return array{organization: ?Organization, college: ?College, department_id: ?string, school_year_id: ?string, academic_year: ?string}
     */
    private function scopeFromData(array $data, ?AdminAssignment $assignment): array
    {
        $organization = isset($data['organization_id'])
            ? Organization::find($data['organization_id'])
            : $assignment?->organization;
        $college = isset($data['college_id'])
            ? College::find($data['college_id'])
            : ($assignment?->college ?: $organization?->college);

        return [
            'organization' => $organization,
            'college' => $college ?: $organization?->college,
            'department_id' => $data['department_id'] ?? $assignment?->department_id ?? $organization?->department_id,
            'school_year_id' => $data['school_year_id'] ?? $assignment?->school_year_id,
            'academic_year' => $data['academic_year'] ?? $assignment?->academic_year,
        ];
    }
}
