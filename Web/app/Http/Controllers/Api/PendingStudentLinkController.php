<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ResolveAttendanceJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PendingStudentLinkController extends Controller
{
    /**
     * List unresolved QR records visible to the authenticated admin.
     */
    public function index(Request $request)
    {
        $query = PendingStudentLink::with(['event', 'organization', 'rawScan'])
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
        if ($student->organization_id !== $pendingStudentLink->organization_id && !$this->canResolveStudentForPendingLink($pendingStudentLink, $user)) {
            abort(403, 'Student is outside the pending link organization boundary.');
        }

        DB::transaction(function () use ($pendingStudentLink, $student, $user, $request): void {
            RawScan::where('event_id', $pendingStudentLink->event_id)
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

        ResolveAttendanceJob::dispatch($pendingStudentLink->event_id, [$student->id]);

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
        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        if ($user->hasRole('LSG Admin')) {
            $userOrg = $user->organization;
            $query->whereHas('organization', function ($orgQuery) use ($userOrg): void {
                $orgQuery->where('college_id', $userOrg?->college_id);
            });
            return;
        }

        $query->where('organization_id', $user->organization_id);
    }

    private function authorizeTenantAccess(PendingStudentLink $pendingStudentLink, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        $targetOrg = Organization::findOrFail($pendingStudentLink->organization_id);
        $userOrg = $user->organization;

        if ($user->hasRole('LSG Admin')) {
            if (!$userOrg || $userOrg->college_id !== $targetOrg->college_id) {
                abort(403, 'Access denied. LSG Admin is restricted to their college boundary.');
            }
            return;
        }

        if ($pendingStudentLink->organization_id !== $user->organization_id) {
            abort(403, 'Access denied. Restricted to your assigned organization boundary.');
        }
    }

    private function canResolveStudentForPendingLink(PendingStudentLink $pendingStudentLink, User $user): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return true;
        }

        if (!$user->hasRole('ARO Admin')) {
            return false;
        }

        $pendingOrg = $pendingStudentLink->organization ?: Organization::find($pendingStudentLink->organization_id);

        return $pendingOrg?->type === 'aro' && $pendingOrg->id === $user->organization_id;
    }
}
