<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\User;
use App\Support\AdminScope;

class AdminScopeService
{
    public function forUser(User $user): ?AdminScope
    {
        $assignment = $user->adminAssignments()
            ->with(['organization', 'college'])
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->whereIn('role_name', AdminAssignmentService::ADMIN_ROLES)
            ->orderByDesc('is_primary_admin')
            ->orderByDesc('approved_at')
            ->first();

        if ($assignment) {
            $organization = $assignment->organization;
            $collegeId = $assignment->college_id ?: $organization?->college_id;
            $college = $assignment->college ?: ($collegeId ? College::find($collegeId) : null);

            return new AdminScope(
                assignment: $assignment,
                roleName: $assignment->role_name,
                organizationId: $assignment->organization_id,
                collegeId: $collegeId,
                organization: $organization,
                college: $college,
            );
        }

        if ($user->hasRole('Super Admin (OSA)')) {
            return new AdminScope(
                assignment: null,
                roleName: 'Super Admin (OSA)',
                organizationId: null,
                collegeId: null,
                organization: null,
                college: null,
            );
        }

        return null;
    }
}
