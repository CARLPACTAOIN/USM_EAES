<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ResolveAttendanceJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\ParticipationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PendingStudentLinkController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes
    ) {}

    /**
     * List unresolved QR records visible to the authenticated admin.
     */
    public function index(Request $request)
    {
        $query = PendingStudentLink::with(['event', 'participationScope.organization', 'organization', 'rawScan'])
            ->where('status', 'pending')
            ->latest();

        $this->applyTenantScope($query, $request->user());

        return response()->json([
            'success' => true,
            'pending_links' => $query->paginate((int) $request->input('per_page', 25)),
        ]);
    }

    /**
     * Resolve an unknown QR value to a canonical student profile.
     */
    public function resolve(Request $request, PendingStudentLink $pendingStudentLink)
    {
        $user = $request->user();
        $this->authorizeTenantAccess($pendingStudentLink, $user);

        if ($pendingStudentLink->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending QR links can be resolved.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'student_id' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $student = User::findOrFail($request->input('student_id'));
        if (! $this->canResolveStudentForPendingLink($pendingStudentLink, $student, $user)) {
            abort(403, 'Student is outside the pending link organization boundary.');
        }

        DB::transaction(function () use ($pendingStudentLink, $student, $user, $request): void {
            RawScan::where('event_id', $pendingStudentLink->event_id)
                ->when($pendingStudentLink->event_participation_scope_id, fn ($query, $scopeId) => $query->where('event_participation_scope_id', $scopeId))
                ->where('qr_code_value', $pendingStudentLink->qr_code_value)
                ->whereNull('student_id')
                ->update(['student_id' => $student->id]);

            $pendingStudentLink->update([
                'status' => 'resolved',
                'resolved_student_id' => $student->id,
                'resolved_by' => $user->id,
                'notes' => $request->input('notes'),
                'resolved_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $pendingStudentLink->id,
                'admin_id' => $user->id,
                'action' => 'resolve-pending-student-link',
                'details' => [
                    'event_id' => $pendingStudentLink->event_id,
                    'qr_code_value' => $pendingStudentLink->qr_code_value,
                    'student_id' => $student->id,
                ],
            ]);
        });

        ResolveAttendanceJob::dispatch($pendingStudentLink->event_id, [$student->id], $pendingStudentLink->event_participation_scope_id);

        return response()->json([
            'success' => true,
            'message' => 'Pending QR link resolved and attendance processing queued.',
            'pending_link' => $pendingStudentLink->fresh(['event', 'resolvedStudent']),
        ]);
    }

    /**
     * Flag an unknown QR value as not belonging to an accredited participant.
     */
    public function flag(Request $request, PendingStudentLink $pendingStudentLink)
    {
        $user = $request->user();
        $this->authorizeTenantAccess($pendingStudentLink, $user);

        if ($pendingStudentLink->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending QR links can be flagged.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $pendingStudentLink->update([
            'status' => 'flagged',
            'flagged_by' => $user->id,
            'notes' => $request->input('notes'),
            'flagged_at' => now(),
        ]);

        AuditLog::create([
            'target_id' => $pendingStudentLink->id,
            'admin_id' => $user->id,
            'action' => 'flag-pending-student-link',
            'details' => [
                'event_id' => $pendingStudentLink->event_id,
                'qr_code_value' => $pendingStudentLink->qr_code_value,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pending QR link flagged.',
            'pending_link' => $pendingStudentLink->fresh(['event', 'organization']),
        ]);
    }

    private function applyTenantScope($query, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (! $scope) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope->isSuperAdmin()) {
            return;
        }

        if (! $scope->organizationId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('organization_id', $scope->organizationId);
    }

    private function authorizeTenantAccess(PendingStudentLink $pendingStudentLink, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if ($pendingStudentLink->participationScope && $this->participationScopes->canViewScope($user, $pendingStudentLink->participationScope)) {
            return;
        }

        $targetOrg = Organization::findOrFail($pendingStudentLink->organization_id);

        if ($scope?->canAccessOrganization($targetOrg)) {
            return;
        }

        abort(403, $scope?->isLsgAdmin()
            ? 'Access denied. LSG Admin is restricted to their college boundary.'
            : 'Access denied. Restricted to your assigned organization boundary.');
    }

    private function canResolveStudentForPendingLink(PendingStudentLink $pendingStudentLink, User $student, User $user): bool
    {
        return $this->participationScopes->canResolveStudentForPendingLink($pendingStudentLink, $student, $user);
    }
}
