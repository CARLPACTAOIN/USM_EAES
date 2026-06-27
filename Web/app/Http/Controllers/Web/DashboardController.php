<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ResolveAttendanceJob;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    /**
     * Admin dashboard home.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // KPI counts scoped by tenant
        $eventsQuery = Event::query();
        $pendingLinksQuery = PendingStudentLink::where('status', 'pending');
        $this->applyEventTenantScope($eventsQuery, $user);
        $this->applyPendingLinkTenantScope($pendingLinksQuery, $user);

        $stats = [
            'total_events' => (clone $eventsQuery)->count(),
            'pending_proposals' => (clone $eventsQuery)->whereIn('status', ['submitted', 'under_review'])->count(),
            'approved_events' => (clone $eventsQuery)->where('status', 'approved')->count(),
            'pending_qr_links' => $pendingLinksQuery->count(),
        ];

        $recentEvents = (clone $eventsQuery)
            ->with('organization')
            ->latest()
            ->take(5)
            ->get();

        return view('dashboard.index', compact('stats', 'recentEvents'));
    }

    /**
     * List events with tenant scoping.
     */
    public function events(Request $request)
    {
        $user = $request->user();
        $canCreateProposals = $user->can('create-proposals');
        $query = Event::with(['organization', 'eventDays', 'parentEvent.organization'])
            ->withCount([
                'evaluations as unprocessed_comment_count' => function ($query): void {
                    $query->where('sentiment', 'unprocessed')
                        ->whereNotNull('open_comment')
                        ->whereRaw("trim(coalesce(open_comment, '')) <> ''");
                },
            ]);
        $this->applyEventTenantScope($query, $user);

        // Optional status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $events = $query->latest()->paginate(15);
        $colleges = College::orderBy('name')->get();
        $organizations = Organization::orderBy('name')->get();
        $parentEvents = Event::with('organization')
            ->whereHas('organization', fn ($query) => $query->whereIn('type', ['usg', 'lsg', 'aro']))
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])
            ->orderByDesc('created_at')
            ->get();

        return view('dashboard.events.index', compact('events', 'user', 'colleges', 'organizations', 'parentEvents', 'canCreateProposals'));
    }

    /**
     * Create a new event proposal.
     */
    public function createEvent(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('create-proposals'), 403);

        $validator = Validator::make($request->all(), [
            'organization_id' => [$user->hasRole('Super Admin (OSA)') ? 'required' : 'nullable', 'uuid', 'exists:organizations,id'],
            'title' => 'required|string|max:255',
            'proposal_category' => 'required|in:program,project,activity',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_type' => 'required|in:on-campus,off-campus',
            'location_details' => 'nullable|string',
            'implementing_office' => 'nullable|string|max:255',
            'collaborating_office' => 'nullable|string|max:255',
            'target_participants' => 'nullable|string|max:1000',
            'source_of_fund' => 'nullable|string|max:255',
            'budget_cost' => 'nullable|numeric|min:0',
            'resolution_number' => 'nullable|string|max:100',
            'parent_event_id' => 'nullable|uuid|exists:events,id',
            'proposal_document' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'hardcopy_submitted' => 'nullable|boolean',
            'head_organization_signed' => 'nullable|boolean',
            'adviser_signed' => 'nullable|boolean',
            'event_days' => 'required|array|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i|after:event_days.*.start_time',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $organizationId = $user->hasRole('Super Admin (OSA)')
            ? $request->input('organization_id')
            : $user->organization_id;

        if (!$organizationId) {
            abort(403, 'Your account must be assigned to an organization before creating proposals.');
        }

        if ($request->filled('parent_event_id')) {
            $parentEvent = Event::with('organization')->findOrFail($request->parent_event_id);
            if (!in_array($parentEvent->organization?->type, ['usg', 'lsg', 'aro'], true)) {
                return redirect()->back()
                    ->with('error', 'Sub-events can only be linked to USG, LSG, or ARO parent events.')
                    ->withInput();
            }
        }

        DB::beginTransaction();
        try {
            $documentPath = $request->file('proposal_document')->store('proposal-documents');
            $hardcopySubmitted = $request->boolean('hardcopy_submitted');

            $event = Event::create([
                'organization_id' => $organizationId,
                'parent_event_id' => $request->parent_event_id,
                'title' => $request->title,
                'proposal_category' => $request->proposal_category,
                'status' => 'draft',
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'location_type' => $request->location_type,
                'location_details' => $request->location_details,
                'target_demographics' => [
                    'implementing_office' => $request->input('implementing_office'),
                    'collaborating_office' => $request->input('collaborating_office'),
                    'target_participants' => $request->input('target_participants'),
                ],
                'budget_allocations' => [
                    'source_of_fund' => $request->input('source_of_fund'),
                    'budget_cost' => $request->input('budget_cost'),
                ],
                'proposal_document_path' => $documentPath,
                'proposal_document_original_name' => $request->file('proposal_document')->getClientOriginalName(),
                'resolution_number' => $request->resolution_number,
                'hardcopy_submitted' => $hardcopySubmitted,
                'hardcopy_submitted_at' => $hardcopySubmitted ? now() : null,
                'head_organization_signed' => $request->boolean('head_organization_signed'),
                'adviser_signed' => $request->boolean('adviser_signed'),
            ]);

            foreach ($request->event_days as $i => $dayData) {
                EventDay::create([
                    'event_id' => $event->id,
                    'day_number' => $i + 1,
                    'date' => $dayData['date'],
                    'start_time' => $dayData['start_time'],
                    'end_time' => $dayData['end_time'],
                ]);
            }

            DB::commit();
            return redirect()->route('dashboard.events')
                ->with('success', 'Event proposal created as draft with the official proposal softcopy attached.');
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($documentPath)) {
                Storage::delete($documentPath);
            }

            return redirect()->back()
                ->with('error', 'Failed to create event: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Submit a draft event for review.
     */
    public function submitEvent(Request $request, $eventId)
    {
        abort_unless($request->user()->can('create-proposals'), 403);

        $event = Event::findOrFail($eventId);
        $this->authorizeEventAccess($event, $request->user());

        if ($event->status !== 'draft') {
            return redirect()->back()->with('error', 'Only draft proposals can be submitted.');
        }

        if (!$event->proposal_document_path) {
            return redirect()->back()->with('error', 'Attach the official PPA proposal softcopy before submitting for review.');
        }

        if (!$event->hardcopy_submitted) {
            return redirect()->back()->with('error', 'Confirm hardcopy submission to OSA before submitting for review.');
        }

        $event->update(['status' => 'submitted']);
        return redirect()->back()->with('success', 'Proposal submitted for review.');
    }

    /**
     * Mark a proposal hardcopy as submitted to OSA.
     */
    public function markHardcopy(Request $request, $eventId)
    {
        abort_unless($request->user()->can('create-proposals'), 403);

        $event = Event::findOrFail($eventId);
        $this->authorizeEventAccess($event, $request->user());

        if (!in_array($event->status, ['draft', 'submitted', 'under_review'], true)) {
            return redirect()->back()->with('error', 'Hardcopy tracking can only be updated before approval or rejection.');
        }

        $event->update([
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'head_organization_signed' => $request->boolean('head_organization_signed', $event->head_organization_signed),
            'adviser_signed' => $request->boolean('adviser_signed', $event->adviser_signed),
            'resolution_number' => $request->filled('resolution_number') ? $request->resolution_number : $event->resolution_number,
        ]);

        return redirect()->back()->with('success', 'Hardcopy submission status updated.');
    }

    /**
     * Start review of a submitted proposal (OSA).
     */
    public function reviewEvent(Request $request, $eventId)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);

        $event = Event::findOrFail($eventId);

        if ($event->status !== 'submitted') {
            return redirect()->back()->with('error', 'Only submitted proposals can be reviewed.');
        }

        $event->update(['status' => 'under_review']);
        return redirect()->back()->with('success', 'Proposal is now under review.');
    }

    /**
     * Approve a proposal (OSA).
     */
    public function approveEvent(Request $request, $eventId)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);

        $event = Event::findOrFail($eventId);

        if (!in_array($event->status, ['submitted', 'under_review'])) {
            return redirect()->back()->with('error', 'Proposal must be submitted or under review to approve.');
        }

        if (!$event->proposal_document_path || !$event->hardcopy_submitted) {
            return redirect()->back()->with('error', 'Proposal softcopy and hardcopy submission must be recorded before approval.');
        }

        $event->update(['status' => 'approved']);
        return redirect()->back()->with('success', 'Event proposal approved. Scanning is now unlocked.');
    }

    /**
     * Reject a proposal (OSA).
     */
    public function rejectEvent(Request $request, $eventId)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);

        $event = Event::findOrFail($eventId);

        if (!in_array($event->status, ['submitted', 'under_review'])) {
            return redirect()->back()->with('error', 'Proposal must be submitted or under review to reject.');
        }

        $event->update(['status' => 'rejected']);
        return redirect()->back()->with('success', 'Event proposal rejected.');
    }

    /**
     * Link a proposal under a parent USG, LSG, or ARO permit.
     */
    public function linkEvent(Request $request, $eventId)
    {
        abort_unless($request->user()->can('create-proposals'), 403);

        $event = Event::findOrFail($eventId);
        $this->authorizeEventAccess($event, $request->user());

        $validator = Validator::make($request->all(), [
            'parent_event_id' => 'required|uuid|exists:events,id',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $parentEvent = Event::with('organization')->findOrFail($request->parent_event_id);
        if (!in_array($parentEvent->organization?->type, ['usg', 'lsg', 'aro'], true)) {
            return redirect()->back()->with('error', 'Sub-events can only be linked to USG, LSG, or ARO parent events.');
        }

        $event->update(['parent_event_id' => $parentEvent->id]);

        return redirect()->back()->with('success', 'Event linked under the parent permit.');
    }

    /**
     * Generate a scanner session deep-link for an approved/completed event.
     */
    public function generateScannerLink(Request $request, $eventId)
    {
        $user = $request->user();
        $event = Event::findOrFail($eventId);
        $this->authorizeEventAccess($event, $user);

        if (!in_array($event->status, ['approved', 'completed'])) {
            return redirect()->back()->with('error', 'Scanner links can only be generated for approved or completed events.');
        }

        // Create a scanner-scoped Sanctum token
        $token = $user->createToken('scanner-' . $event->id, ['scan-qr-codes', 'manual-entry-id'])->plainTextToken;
        $link = "eaes://scanner?token={$token}&event_id={$event->id}";

        return redirect()->back()->with('success', 'Scanner link generated.')->with('scanner_link', $link);
    }

    /**
     * Download the official proposal softcopy.
     */
    public function downloadProposal(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        $this->authorizeEventAccess($event, $request->user());

        if (!$event->proposal_document_path || !Storage::exists($event->proposal_document_path)) {
            abort(404, 'Proposal document not found.');
        }

        return Storage::download(
            $event->proposal_document_path,
            $event->proposal_document_original_name ?: 'proposal-document'
        );
    }

    /**
     * List pending student QR links.
     */
    public function pendingLinks(Request $request)
    {
        $user = $request->user();
        $query = PendingStudentLink::with(['event', 'organization', 'rawScan'])
            ->where('status', 'pending')
            ->latest();

        $this->applyPendingLinkTenantScope($query, $user);

        $pendingLinks = $query->paginate(15);

        return view('dashboard.pending-links.index', compact('pendingLinks'));
    }

    /**
     * Resolve a pending QR link to a student.
     */
    public function resolveLink(Request $request, $id)
    {
        $user = $request->user();
        $pendingLink = PendingStudentLink::findOrFail($id);
        $this->authorizePendingLinkAccess($pendingLink, $user);

        if ($pendingLink->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending links can be resolved.');
        }

        $validator = Validator::make($request->all(), [
            'student_id' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $student = User::findOrFail($request->student_id);
        if (!$this->canResolveStudentForPendingLink($pendingLink, $student, $user)) {
            abort(403, 'Student is outside the pending link organization boundary.');
        }

        DB::transaction(function () use ($pendingLink, $student, $user, $request) {
            RawScan::where('event_id', $pendingLink->event_id)
                ->where('qr_code_value', $pendingLink->qr_code_value)
                ->whereNull('student_id')
                ->update(['student_id' => $student->id]);

            $pendingLink->update([
                'status' => 'resolved',
                'resolved_student_id' => $student->id,
                'resolved_by' => $user->id,
                'notes' => $request->notes,
                'resolved_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $pendingLink->id,
                'admin_id' => $user->id,
                'action' => 'resolve-pending-student-link',
                'details' => [
                    'event_id' => $pendingLink->event_id,
                    'qr_code_value' => $pendingLink->qr_code_value,
                    'student_id' => $student->id,
                ],
            ]);
        });

        ResolveAttendanceJob::dispatch($pendingLink->event_id, [$student->id]);

        return redirect()->back()->with('success', 'Pending QR link resolved successfully.');
    }

    /**
     * Flag a pending QR link as unaccredited.
     */
    public function flagLink(Request $request, $id)
    {
        $user = $request->user();
        $pendingLink = PendingStudentLink::findOrFail($id);
        $this->authorizePendingLinkAccess($pendingLink, $user);

        if ($pendingLink->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending links can be flagged.');
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $pendingLink->update([
            'status' => 'flagged',
            'flagged_by' => $user->id,
            'notes' => $request->notes,
            'flagged_at' => now(),
        ]);

        AuditLog::create([
            'target_id' => $pendingLink->id,
            'admin_id' => $user->id,
            'action' => 'flag-pending-student-link',
            'details' => [
                'event_id' => $pendingLink->event_id,
                'qr_code_value' => $pendingLink->qr_code_value,
            ],
        ]);

        return redirect()->back()->with('success', 'Pending QR link flagged.');
    }

    /**
     * List admin users (Super Admin only).
     */
    public function adminUsers(Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin (OSA)')) {
            abort(403);
        }

        $adminRoles = ['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'ARO Admin'];
        $admins = User::role($adminRoles)->with('organization')->orderBy('name')->paginate(15);
        $organizations = Organization::with('college')->orderBy('name')->get();

        return view('dashboard.admin-users.index', compact('admins', 'organizations', 'adminRoles'));
    }

    /**
     * Create a new admin user (PRD Feature 1.3).
     */
    public function createAdminUser(Request $request, AdminAssignmentService $service)
    {
        $user = $request->user();
        if (!$user->hasRole('Super Admin (OSA)')) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|string|in:Super Admin (OSA),USG Admin,LSG Admin,Society Admin,ARO Admin',
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $organization = $request->filled('organization_id') ? Organization::find($request->organization_id) : null;
        $newAdmin = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $newAdmin->assignRole('Student');
        $service->createAssignment(
            user: $newAdmin,
            roleName: $request->role,
            organization: $organization,
            college: $organization?->college,
            academicYear: now()->year . '-' . (now()->year + 1),
            termStart: now()->toDateString(),
            termEnd: now()->addYear()->toDateString(),
            positionTitle: 'Direct OSA assignment',
            approvedBy: $user,
            isPrimaryAdmin: true
        );

        return redirect()->route('dashboard.admin-users')
            ->with('success', "Admin account for {$newAdmin->name} created with role {$request->role}.");
    }

    // ─── Tenant Scoping Helpers ────────────────────────────

    private function applyEventTenantScope($query, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            $query->where('status', '!=', 'draft');
            return;
        }

        if ($user->hasRole('USG Admin')) {
            $query->whereHas('organization', fn($q) => $q->where('type', 'usg')->orWhere('id', $user->organization_id));
        } elseif ($user->hasRole('LSG Admin')) {
            $userOrg = $user->organization;
            if ($userOrg) {
                $query->whereHas('organization', fn($q) => $q->where('college_id', $userOrg->college_id));
            }
        } else {
            $query->where('organization_id', $user->organization_id);
        }
    }

    private function applyPendingLinkTenantScope($query, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) return;

        if ($user->hasRole('LSG Admin')) {
            $userOrg = $user->organization;
            $query->whereHas('organization', fn($q) => $q->where('college_id', $userOrg?->college_id));
        } else {
            $query->where('organization_id', $user->organization_id);
        }
    }

    private function authorizeEventAccess(Event $event, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            if ($event->status === 'draft') {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        if ($user->hasRole('LSG Admin')) {
            $userOrg = $user->organization;
            $eventOrg = $event->organization;
            if ($userOrg && $eventOrg && $userOrg->college_id !== $eventOrg->college_id) {
                abort(403, 'Access denied. LSG Admin restricted to college boundary.');
            }
            return;
        }

        if ($event->organization_id !== $user->organization_id) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }

    private function authorizePendingLinkAccess(PendingStudentLink $pendingLink, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) return;

        $targetOrg = $pendingLink->organization;
        $userOrg = $user->organization;

        if ($user->hasRole('LSG Admin')) {
            if (!$userOrg || !$targetOrg || $userOrg->college_id !== $targetOrg->college_id) {
                abort(403, 'Access denied. LSG Admin restricted to college boundary.');
            }
            return;
        }

        if ($pendingLink->organization_id !== $user->organization_id) {
            abort(403, 'Access denied. Restricted to your organization boundary.');
        }
    }

    private function canResolveStudentForPendingLink(PendingStudentLink $pendingLink, User $student, User $admin): bool
    {
        if ($student->organization_id === $pendingLink->organization_id) {
            return true;
        }

        if ($admin->hasRole('Super Admin (OSA)')) {
            return true;
        }

        $pendingOrg = $pendingLink->organization;

        return $admin->hasRole('ARO Admin')
            && $pendingOrg?->type === 'aro'
            && $pendingOrg->id === $admin->organization_id;
    }
}
