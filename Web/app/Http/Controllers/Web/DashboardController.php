<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ResolveAttendanceJob;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventScannerAssignment;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\ScannerSession;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\AdminScopeService;
use App\Services\EventNotificationService;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use App\Support\AdminScope;
use App\Support\EventEvaluationForm;
use App\Support\EventTenantScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ScannerSessionService $scannerSessions,
        private readonly ScannerAssignmentService $scannerAssignments,
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventNotificationService $eventNotifications
    ) {
    }

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
        $needsActionItems = $this->eventNotifications->needsActionItemsForUser($user, 5);
        $notificationSummary = $this->eventNotifications->summaryForUser($user);
        $notificationDropdownItems = $user->notifications()
            ->latest()
            ->limit(8)
            ->get();

        $recentEvents = (clone $eventsQuery)
            ->with('organization')
            ->latest()
            ->take(5)
            ->get();

        return view('dashboard.index', compact(
            'stats',
            'recentEvents',
            'needsActionItems',
            'notificationSummary',
            'notificationDropdownItems'
        ));
    }

    /**
     * List events with tenant scoping.
     */
    public function events(Request $request)
    {
        $user = $request->user();
        $canCreateProposals = $user->can('create-proposals');

        return view('dashboard.events.index', compact(
            'user',
            'canCreateProposals'
        ));
    }

    public function scannerCandidates(Request $request, Event $event)
    {
        if (!in_array($event->status, ['approved', 'completed'], true)) {
            return response()->json([
                'data' => [],
                'message' => 'Scanner candidates are available only after the event is approved.',
            ], 422);
        }

        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'event_participation_scope_id' => 'nullable|uuid|exists:event_participation_scopes,id',
        ]);

        $participationScope = null;
        if (filled($validated['event_participation_scope_id'] ?? null)) {
            $participationScope = EventParticipationScope::findOrFail($validated['event_participation_scope_id']);
            abort_unless($participationScope->event_id === $event->id, 404);
            $this->authorizeScannerScopeAccess($request->user(), $participationScope);

            if ($this->participationScopes->runtimeGuard($participationScope)) {
                return response()->json(['data' => []]);
            }
        } else {
            $this->authorizeScannerAssignmentAccess($event, $request->user());
        }

        $candidates = $this->scannerAssignments->eligibleUsers(
            search: $validated['q'] ?? null,
            limit: 12,
            admin: $request->user(),
            participationScope: $participationScope
        );

        return response()->json([
            'data' => $candidates
                ->map(fn (User $candidate) => $this->scannerAssignments->candidatePayload($candidate))
                ->values(),
        ]);
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
            'proposal_document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'hardcopy_submitted' => 'nullable|boolean',
            'head_organization_signed' => 'nullable|boolean',
            'adviser_signed' => 'nullable|boolean',
            'event_days' => 'required|array|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i',
            'evaluation_questions' => 'required|array|min:1|max:10',
            'evaluation_questions.*.id' => 'nullable|string|max:80',
            'evaluation_questions.*.label' => 'required|string|max:180',
            'evaluation_questions.*.description' => 'nullable|string|max:500',
        ], [], [
            'organization_id' => 'organizer',
            'proposal_category' => 'PPA category',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'location_type' => 'location type',
            'location_details' => 'location details',
            'implementing_office' => 'implementing office',
            'collaborating_office' => 'collaborating office',
            'target_participants' => 'target participants',
            'source_of_fund' => 'source of fund',
            'budget_cost' => 'budget cost',
            'resolution_number' => 'resolution number',
            'proposal_document' => 'official PPA proposal softcopy',
            'event_days' => 'event days',
            'event_days.*.date' => 'event day date',
            'event_days.*.start_time' => 'event day start time',
            'event_days.*.end_time' => 'event day end time',
            'evaluation_questions' => 'evaluation questions',
            'evaluation_questions.*.label' => 'evaluation question',
            'evaluation_questions.*.description' => 'evaluation question description',
        ]);

        $validator->after(function ($validator) use ($request): void {
            try {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->input('end_date'))->startOfDay();
            } catch (\Throwable) {
                return;
            }

            foreach ((array) $request->input('event_days', []) as $index => $dayData) {
                if (empty($dayData['date'])) {
                    continue;
                }

                try {
                    $dayDate = Carbon::parse($dayData['date'])->startOfDay();
                } catch (\Throwable) {
                    continue;
                }

                if ($dayDate->lt($startDate) || $dayDate->gt($endDate)) {
                    $validator->errors()->add(
                        "event_days.$index.date",
                        sprintf('Day %d date must be within the event start and end dates.', $index + 1)
                    );
                }

                $startTime = (string) ($dayData['start_time'] ?? '');
                $endTime = (string) ($dayData['end_time'] ?? '');

                if ($startTime !== '' && $endTime !== '' && $endTime <= $startTime) {
                    $validator->errors()->add(
                        "event_days.$index.end_time",
                        sprintf('Day %d end time must be later than the start time.', $index + 1)
                    );
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $scope = $this->adminScopes->forUser($user);

        $organizationId = $scope?->isSuperAdmin()
            ? $request->input('organization_id')
            : $scope?->organizationId;

        if (!$organizationId) {
            abort(403, 'Your account must be assigned to an organization before creating proposals.');
        }

        $organization = Organization::find($organizationId);
        if (!$organization || !in_array($organization->type, ['society', 'usg', 'lsg', 'academic_unit', 'administrative_office', 'non_academic_org', 'hr_unit'], true)) {
            return redirect()->back()
                ->with('error', 'Select an active recognized organizer before creating a proposal.')
                ->withInput();
        }

        $isHrEvent = $organization->type === 'hr_unit';
        if (!$isHrEvent && !$request->hasFile('proposal_document')) {
            return redirect()->back()
                ->withErrors(['proposal_document' => 'Attach the official PPA proposal softcopy before saving this event.'])
                ->withInput();
        }

        if ($request->filled('parent_event_id')) {
            $parentEvent = Event::with('organization')->findOrFail($request->parent_event_id);
            if (!in_array($parentEvent->organization?->type, ['usg', 'lsg', 'academic_unit', 'administrative_office'], true)) {
                return redirect()->back()
                    ->with('error', 'Sub-events can only be linked to USG or LSG parent events.')
                    ->withInput();
            }
        }

        DB::beginTransaction();
        try {
            $documentPath = $request->hasFile('proposal_document')
                ? $request->file('proposal_document')->store('proposal-documents')
                : null;
            $hardcopySubmitted = $isHrEvent ? false : $request->boolean('hardcopy_submitted');

            $event = Event::create([
                'organization_id' => $organizationId,
                'parent_event_id' => $request->parent_event_id,
                'title' => $request->title,
                'event_domain' => $organization->type === 'hr_unit' ? 'hr' : 'student_affairs',
                'proposal_category' => $request->proposal_category,
                'status' => $isHrEvent ? 'approved' : 'draft',
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
                'proposal_document_original_name' => $request->hasFile('proposal_document')
                    ? $request->file('proposal_document')->getClientOriginalName()
                    : null,
                'resolution_number' => $request->resolution_number,
                'hardcopy_submitted' => $hardcopySubmitted,
                'hardcopy_submitted_at' => $hardcopySubmitted ? now() : null,
                'head_organization_signed' => $isHrEvent ? false : $request->boolean('head_organization_signed'),
                'adviser_signed' => $isHrEvent ? false : $request->boolean('adviser_signed'),
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

            EventEvaluationForm::createForEvent(
                $event,
                $user,
                $request->input('evaluation_questions', []),
                'Evaluation - ' . $event->title
            );

            $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']));

            DB::commit();
            return redirect()->route('dashboard.events')
                ->with('success', $isHrEvent
                    ? 'HR attendance event created and scanner access is ready.'
                    : 'Event proposal created as draft with the official proposal softcopy attached.');
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
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);
        $this->eventNotifications->notifyPendingInvitationsForEvent($event->fresh(['organization', 'participationScopes.organization', 'participationScopes.delegatedOrganization']));

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
        $this->participationScopes->ensureOrganizerScope($event->fresh())->update([
            'hardcopy_submitted' => true,
            'org_head_signed' => $request->boolean('head_organization_signed', $event->head_organization_signed),
            'adviser_signed' => $request->boolean('adviser_signed', $event->adviser_signed),
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
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
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

        if (!EventEvaluationForm::hasLockedForm($event)) {
            return redirect()->back()->with('error', 'A locked evaluation form with at least one question is required before approval.');
        }

        $event->update(['status' => 'approved']);
        $this->participationScopes->ensureOrganizerScope($event->fresh())->update([
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);
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
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        return redirect()->back()->with('success', 'Event proposal rejected.');
    }

    /**
     * Link a proposal under a parent USG or LSG permit.
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
        if (!in_array($parentEvent->organization?->type, ['usg', 'lsg', 'academic_unit', 'administrative_office'], true)) {
            return redirect()->back()->with('error', 'Sub-events can only be linked to USG or LSG parent events.');
        }

        $event->update(['parent_event_id' => $parentEvent->id]);
        $participantScope = $this->syncParticipantScopeFromLinkedEvent($parentEvent, $event);
        $this->eventNotifications->notifyParticipantScopeInvitation($participantScope->fresh(['event.organization', 'organization', 'delegatedOrganization']));

        return redirect()->back()->with('success', 'Event linked under the parent permit.');
    }

    public function storeParticipantScope(Request $request, Event $event)
    {
        abort_unless($request->user()->can('create-proposals') && !$request->user()->hasRole('Super Admin (OSA)'), 403);

        $this->authorizeEventAccess($event, $request->user());

        $validated = $request->validate([
            'audience_mode' => 'nullable|string|in:all,college,organization',
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'organization_ids' => 'nullable|array',
            'organization_ids.*' => 'uuid|exists:organizations,id',
            'college_id' => 'nullable|uuid|exists:colleges,id',
            'college_ids' => 'nullable|array',
            'college_ids.*' => 'uuid|exists:colleges,id',
            'program_id' => 'nullable|uuid|exists:programs,id',
            'participant_name_snapshot' => 'nullable|string|max:255',
            'participant_type' => 'nullable|string|in:college,organization,society,program,non_academic_org,academic_unit,administrative_office,custom_roster,hr_unit',
            'ppa_required' => 'nullable|boolean',
            'capture_mode' => 'required|in:self_scanning,parent_scanning,delegated_scanning,central_gate',
            'analytics_rollup_enabled' => 'nullable|boolean',
        ]);

        $organizationIds = collect($validated['organization_ids'] ?? [])
            ->when(filled($validated['organization_id'] ?? null), fn ($ids) => $ids->push($validated['organization_id']))
            ->filter()
            ->unique()
            ->values();
        $collegeIds = collect($validated['college_ids'] ?? [])
            ->when(filled($validated['college_id'] ?? null), fn ($ids) => $ids->push($validated['college_id']))
            ->filter()
            ->unique()
            ->values();

        $audienceMode = $validated['audience_mode'] ?? match (true) {
            $collegeIds->isNotEmpty() && $organizationIds->isEmpty() => 'college',
            default => 'organization',
        };

        if ($audienceMode === 'all') {
            $collegeIds = College::orderBy('name')->pluck('id')->values();
        }

        if ($audienceMode === 'organization' && $organizationIds->isEmpty()) {
            return redirect()->back()
                ->withErrors(['organization_ids' => 'Select at least one organization audience.'])
                ->withInput();
        }

        if (in_array($audienceMode, ['all', 'college'], true) && $collegeIds->isEmpty()) {
            return redirect()->back()
                ->withErrors(['college_ids' => 'Select at least one College audience.'])
                ->withInput();
        }

        $ppaRequired = $request->boolean('ppa_required', true);
        $analyticsRollupEnabled = $request->boolean('analytics_rollup_enabled', true);
        $captureMode = $validated['capture_mode'];
        $parentScope = $this->participationScopes->ensureOrganizerScope($event);
        $createdScopes = collect();
        $skippedOrganizer = 0;
        $skippedExisting = 0;

        DB::transaction(function () use (
            $audienceMode,
            $organizationIds,
            $collegeIds,
            $event,
            $parentScope,
            $validated,
            $ppaRequired,
            $analyticsRollupEnabled,
            $captureMode,
            &$createdScopes,
            &$skippedOrganizer,
            &$skippedExisting
        ): void {
            if ($audienceMode === 'organization') {
                Organization::query()
                    ->whereIn('id', $organizationIds)
                    ->orderBy('name')
                    ->get()
                    ->each(function (Organization $organization) use (
                        $event,
                        $parentScope,
                        $validated,
                        $organizationIds,
                        $ppaRequired,
                        $analyticsRollupEnabled,
                        $captureMode,
                        &$createdScopes,
                        &$skippedOrganizer,
                        &$skippedExisting
                    ): void {
                        if ($organization->id === $event->organization_id) {
                            $skippedOrganizer++;
                            return;
                        }

                        $exists = $event->participationScopes()
                            ->where('organization_id', $organization->id)
                            ->where('status', EventParticipationScope::STATUS_ACTIVE)
                            ->exists();

                        if ($exists) {
                            $skippedExisting++;
                            return;
                        }

                        $createdScopes->push($event->participationScopes()->create([
                            'parent_scope_id' => $parentScope->id,
                            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                            'participant_type' => $this->participationScopes->participantTypeForOrganization($organization),
                            'organization_id' => $organization->id,
                            'college_id' => $organization->college_id,
                            'program_id' => $validated['program_id'] ?? null,
                            'participant_name_snapshot' => $organizationIds->count() === 1
                                ? ($validated['participant_name_snapshot'] ?? $this->participationScopes->participantName($organization, 'Participant scope'))
                                : $this->participationScopes->participantName($organization, 'Participant scope'),
                            'ppa_required' => $ppaRequired,
                            'ppa_status' => $ppaRequired ? EventParticipationScope::PPA_DRAFT : EventParticipationScope::PPA_NOT_REQUIRED,
                            'capture_mode' => $captureMode,
                            'analytics_rollup_enabled' => $analyticsRollupEnabled,
                            'status' => EventParticipationScope::STATUS_ACTIVE,
                        ]));
                    });

                return;
            }

            College::query()
                ->whereIn('id', $collegeIds)
                ->orderBy('name')
                ->get()
                ->each(function (College $college) use (
                    $event,
                    $parentScope,
                    $ppaRequired,
                    $analyticsRollupEnabled,
                    $captureMode,
                    &$createdScopes,
                    &$skippedExisting
                ): void {
                    $exists = $event->participationScopes()
                        ->where('participant_type', 'college')
                        ->whereNull('organization_id')
                        ->where('college_id', $college->id)
                        ->where('status', EventParticipationScope::STATUS_ACTIVE)
                        ->exists();

                    if ($exists) {
                        $skippedExisting++;
                        return;
                    }

                    $createdScopes->push($event->participationScopes()->create([
                        'parent_scope_id' => $parentScope->id,
                        'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                        'participant_type' => 'college',
                        'organization_id' => null,
                        'college_id' => $college->id,
                        'program_id' => null,
                        'participant_name_snapshot' => trim(($college->code ? "{$college->code} - " : '') . $college->name),
                        'ppa_required' => $ppaRequired,
                        'ppa_status' => $ppaRequired ? EventParticipationScope::PPA_DRAFT : EventParticipationScope::PPA_NOT_REQUIRED,
                        'capture_mode' => $captureMode,
                        'analytics_rollup_enabled' => $analyticsRollupEnabled,
                        'status' => EventParticipationScope::STATUS_ACTIVE,
                    ]));
                });
        });

        $createdScopes->each(fn (EventParticipationScope $participantScope) => $this->eventNotifications
            ->notifyParticipantScopeInvitation($participantScope->fresh(['event.organization', 'organization', 'college', 'delegatedOrganization'])));

        if ($createdScopes->isNotEmpty()) {
            $message = $createdScopes->count() === 1
                ? 'Participant scope added.'
                : "{$createdScopes->count()} participant scopes added.";

            if ($skippedOrganizer > 0) {
                $message .= ' Organizer audience was skipped because it is already included.';
            }

            if ($skippedExisting > 0) {
                $message .= " {$skippedExisting} duplicate audience selection(s) were skipped.";
            }

            return redirect()->back()->with('success', $message);
        }

        if ($skippedOrganizer > 0 && $skippedExisting === 0) {
            return redirect()->back()->with('error', 'The organizer is already included through the organizer attendance scope and does not need a participant PPA.');
        }

        return redirect()->back()->with('error', 'Every selected audience already has an active participant scope for the event.');
    }

    public function acknowledgeParticipantScope(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        abort_unless($participationScope->event_id === $event->id, 404);

        if (!$this->eventNotifications->acknowledgeInvitation($participationScope, $request->user())) {
            abort(403, 'Only the invited participant organization can acknowledge this invitation.');
        }

        return redirect()->route('dashboard.events', ['workspace' => 'participating'])
            ->with('success', 'Participant invitation acknowledged.');
    }

    public function submitParticipantScope(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        $this->authorizeParticipantScopeAccess($event, $participationScope, $request->user());

        if (!$participationScope->ppa_required) {
            return redirect()->back()->with('error', 'This participant scope does not require a PPA.');
        }

        if (!in_array($participationScope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REJECTED], true)) {
            return redirect()->back()->with('error', 'Only draft or rejected participant PPAs can be submitted.');
        }

        $validated = $request->validate([
            'ppa_softcopy' => [$participationScope->ppa_softcopy_path ? 'nullable' : 'required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'hardcopy_submitted' => 'nullable|boolean',
            'org_head_signed' => 'nullable|boolean',
            'adviser_signed' => 'nullable|boolean',
        ]);

        $updates = [
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'hardcopy_submitted' => $request->boolean('hardcopy_submitted'),
            'org_head_signed' => $request->boolean('org_head_signed'),
            'adviser_signed' => $request->boolean('adviser_signed'),
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
            'review_remarks' => null,
        ];

        if ($request->hasFile('ppa_softcopy')) {
            $updates['ppa_softcopy_path'] = $request->file('ppa_softcopy')->store('participant-ppa-documents');
            $updates['ppa_softcopy_original_name'] = $request->file('ppa_softcopy')->getClientOriginalName();
        }

        $participationScope->update($updates);
        $this->eventNotifications->notifyParticipantScopeSubmitted($participationScope->fresh(['event.organization', 'organization', 'delegatedOrganization']));

        return redirect()->back()->with('success', 'Participant PPA submitted for OSA review.');
    }

    public function updateParticipantScopeAttendancePolicy(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        abort_unless(!$request->user()->hasRole('Super Admin (OSA)'), 403);
        $this->authorizeParticipantScopeAccess($event, $participationScope, $request->user());

        if ($this->attendancePolicyLocked($event)) {
            return redirect()->back()->with('error', 'Attendance rules are locked after the event starts.');
        }

        if ($event->event_late_cutoff_min !== null) {
            return redirect()->back()->with('error', 'This event already has an organizer late cutoff. Participant scope cutoffs are available only when the organizer leaves the event cutoff unset.');
        }

        $validated = $request->validate([
            'late_cutoff_min' => 'nullable|integer|min:0|max:240',
        ]);

        $participationScope->update([
            'late_cutoff_min' => filled($validated['late_cutoff_min'] ?? null)
                ? (int) $validated['late_cutoff_min']
                : null,
        ]);

        $studentIds = RawScan::query()
            ->where('event_id', $event->id)
            ->where('event_participation_scope_id', $participationScope->id)
            ->whereNotNull('student_id')
            ->distinct()
            ->pluck('student_id')
            ->all();

        if ($studentIds) {
            ResolveAttendanceJob::dispatch($event->id, $studentIds, $participationScope->id);
        }

        return redirect()->back()->with('success', 'Participant late cutoff updated.');
    }

    public function updateEventAttendancePolicy(Request $request, Event $event)
    {
        $user = $request->user();
        abort_unless(!$user->hasRole('Super Admin (OSA)') && $user->can('create-proposals'), 403);
        $this->authorizeEventAccess($event, $user);
        $adminScope = $this->adminScopes->forUser($user);
        abort_unless($adminScope?->organizationId && $event->organization_id === $adminScope->organizationId, 403, 'Only the event organizer can update attendance rules.');

        if ($this->attendancePolicyLocked($event)) {
            return redirect()->back()->with('error', 'Attendance rules are locked after the event starts.');
        }

        $validated = $request->validate([
            'event_late_cutoff_min' => 'nullable|integer|min:0|max:240',
        ]);

        $event->update([
            'event_late_cutoff_min' => filled($validated['event_late_cutoff_min'] ?? null)
                ? (int) $validated['event_late_cutoff_min']
                : null,
        ]);

        return redirect()->back()->with('success', 'Attendance rules updated.');
    }

    public function reviewParticipantScope(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);
        abort_unless($participationScope->event_id === $event->id, 404);

        if ($participationScope->ppa_status !== EventParticipationScope::PPA_SUBMITTED) {
            return redirect()->back()->with('error', 'Only submitted participant PPAs can be reviewed.');
        }

        $participationScope->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->eventNotifications->notifyParticipantScopeReviewed($participationScope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_UNDER_REVIEW);

        return redirect()->back()->with('success', 'Participant PPA is now under review.');
    }

    public function approveParticipantScope(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);
        abort_unless($participationScope->event_id === $event->id, 404);

        if (!in_array($participationScope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true)) {
            return redirect()->back()->with('error', 'Participant PPA must be submitted or under review before approval.');
        }

        if ($participationScope->ppa_required && (!$participationScope->ppa_softcopy_path || !$participationScope->hardcopy_submitted)) {
            return redirect()->back()->with('error', 'Participant PPA softcopy and hardcopy submission are required before approval.');
        }

        $participationScope->update([
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
            'review_remarks' => null,
        ]);
        $this->eventNotifications->notifyParticipantScopeReviewed($participationScope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_APPROVED);

        return redirect()->back()->with('success', 'Participant PPA approved. Scoped scanner access is now available.');
    }

    public function rejectParticipantScope(Request $request, Event $event, EventParticipationScope $participationScope)
    {
        abort_unless($request->user()->hasRole('Super Admin (OSA)'), 403);
        abort_unless($participationScope->event_id === $event->id, 404);

        $validated = $request->validate([
            'review_remarks' => 'nullable|string|max:1000',
        ]);

        if (!in_array($participationScope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true)) {
            return redirect()->back()->with('error', 'Participant PPA must be submitted or under review before rejection.');
        }

        $participationScope->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['review_remarks'] ?? null,
        ]);
        $this->eventNotifications->notifyParticipantScopeReviewed($participationScope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_REJECTED);

        return redirect()->back()->with('success', 'Participant PPA rejected.');
    }

    public function revokeScannerSession(Request $request, Event $event, ScannerSession $scannerSession)
    {
        abort_unless($scannerSession->event_id === $event->id, 404);
        $scannerSession->loadMissing('participationScope');
        if ($scannerSession->participationScope) {
            $this->authorizeScannerScopeAccess($request->user(), $scannerSession->participationScope);
        } else {
            $this->authorizeEventAccess($event, $request->user());
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $this->scannerSessions->revoke(
            $scannerSession,
            $request->user(),
            $validated['reason'] ?? 'Revoked from dashboard.'
        );

        return redirect()->back()->with('success', 'Scanner session revoked.');
    }

    public function assignEventScanner(Request $request, Event $event)
    {
        if ($event->status !== 'approved') {
            return redirect()->back()->with('error', 'Scanner assignments can only be created for approved events.');
        }

        $validated = $request->validate([
            'event_participation_scope_id' => 'required|uuid|exists:event_participation_scopes,id',
            'scanner_user_id' => 'nullable|uuid|exists:users,id',
            'scanner_user_ids' => 'nullable|array|min:1|max:25',
            'scanner_user_ids.*' => 'required|uuid|distinct|exists:users,id',
            'label' => 'nullable|string|max:120',
            'max_devices' => 'nullable|integer|min:1|max:10',
            'notes' => 'nullable|string|max:1000',
        ]);

        $scannerUserIds = collect($validated['scanner_user_ids'] ?? [])
            ->when(filled($validated['scanner_user_id'] ?? null), fn ($ids) => $ids->push($validated['scanner_user_id']))
            ->filter()
            ->unique()
            ->values();

        if ($scannerUserIds->isEmpty()) {
            return redirect()->back()
                ->with('error', 'Choose at least one scanner from your authorized organization or college.')
                ->withInput();
        }

        $scannerUsers = User::whereKey($scannerUserIds)->get()->keyBy('id');
        $participationScope = EventParticipationScope::findOrFail($validated['event_participation_scope_id']);
        abort_unless($participationScope->event_id === $event->id, 404);
        $this->authorizeScannerScopeAccess($request->user(), $participationScope);

        foreach ($scannerUserIds as $scannerUserId) {
            $scannerUser = $scannerUsers->get($scannerUserId);
            if (!$scannerUser || !$this->scannerAssignments->canAssignScanner($request->user(), $scannerUser, $participationScope)) {
                return redirect()->back()
                    ->with('error', 'Select scanners from the selected attendance scope.')
                    ->withInput();
            }
        }

        DB::transaction(function () use ($event, $scannerUserIds, $scannerUsers, $request, $validated, $participationScope): void {
            foreach ($scannerUserIds as $scannerUserId) {
                $this->scannerAssignments->assign(
                    event: $event,
                    scannerUser: $scannerUsers->get($scannerUserId),
                    assignedBy: $request->user(),
                    label: $validated['label'] ?? null,
                    maxDevices: $validated['max_devices'] ?? 1,
                    notes: $validated['notes'] ?? null,
                    participationScope: $participationScope
                );
            }
        });

        $count = $scannerUserIds->count();
        $message = $count === 1
            ? "{$scannerUsers->get($scannerUserIds->first())->name} is now assigned as a scanner for {$event->title}."
            : "{$count} scanners are now assigned for {$event->title}.";

        return redirect()->back()->with('success', $message);
    }

    public function revokeEventScannerAssignment(Request $request, Event $event, EventScannerAssignment $assignment)
    {
        abort_unless($assignment->event_id === $event->id, 404);
        $assignment->loadMissing('participationScope');
        if ($assignment->participationScope) {
            $this->authorizeScannerScopeAccess($request->user(), $assignment->participationScope);
        } else {
            $this->authorizeScannerAssignmentAccess($event, $request->user());
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $this->scannerAssignments->revoke(
            $assignment,
            $request->user(),
            $validated['reason'] ?? 'Scanner assignment revoked from dashboard.'
        );

        return redirect()->back()->with('success', 'Scanner assignment revoked.');
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
        $query = PendingStudentLink::with(['event', 'participationScope.organization', 'organization', 'rawScan'])
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
                ->when($pendingLink->event_participation_scope_id, fn ($query, $scopeId) => $query->where('event_participation_scope_id', $scopeId))
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

        ResolveAttendanceJob::dispatch($pendingLink->event_id, [$student->id], $pendingLink->event_participation_scope_id);

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

        $adminRoles = AdminAssignmentService::ADMIN_ROLES;
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
            'role' => 'required|string|in:' . implode(',', AdminAssignmentService::ADMIN_ROLES),
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
            'user_type' => str_starts_with(strtolower($request->email), 's.') ? 'student' : 'faculty_or_staff_pending',
        ]);

        if ($newAdmin->user_type === 'student') {
            $newAdmin->assignRole('Student');
        }
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
        $scope = $this->adminScopes->forUser($user);

        EventTenantScope::apply($query, $user);

        if ($scope?->isSuperAdmin()) {
            $query->where('status', '!=', 'draft');
        }
    }

    private function applyParticipatingEventScope($query, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query
            ->where('status', '!=', 'draft')
            ->whereHas('participationScopes', function ($scopeQuery) use ($scope): void {
                $scopeQuery
                    ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                    ->where('status', EventParticipationScope::STATUS_ACTIVE);

                $this->applyParticipantScopeBoundary($scopeQuery, $scope);
            });
    }

    private function applyParticipantScopeBoundary($query, AdminScope $scope): void
    {
        if ($scope->isSuperAdmin()) {
            return;
        }

        $query->where(function ($boundaryQuery) use ($scope): void {
            $hasBoundary = false;

            if ($scope->organizationId) {
                $hasBoundary = true;
                $boundaryQuery
                    ->where('organization_id', $scope->organizationId)
                    ->orWhere('delegated_to_organization_id', $scope->organizationId);
            }

            if ($scope->isLsgAdmin() && $scope->collegeId) {
                $hasBoundary = true;
                $boundaryQuery
                    ->orWhere('college_id', $scope->collegeId)
                    ->orWhereHas('organization', fn ($organizationQuery) => $organizationQuery->where('college_id', $scope->collegeId))
                    ->orWhereHas('delegatedOrganization', fn ($organizationQuery) => $organizationQuery->where('college_id', $scope->collegeId));
            }

            if (!$hasBoundary) {
                $boundaryQuery->whereRaw('1 = 0');
            }
        });
    }

    private function annotateEventsForDashboard($events, User $user, string $workspace): void
    {
        $adminScope = $this->adminScopes->forUser($user);

        $events->each(function (Event $event) use ($adminScope, $user, $workspace): void {
            $event->setAttribute('attendance_policy_locked', $this->attendancePolicyLocked($event));

            $event->participationScopes->each(function (EventParticipationScope $scope) use ($adminScope, $user): void {
                $scope->setAttribute('current_admin_owns_scope', $this->adminScopeMatchesParticipantScope($adminScope, $scope));
                $scope->setAttribute('current_admin_can_manage_scope', $this->participationScopes->canManageScope($user, $scope));
            });

            if ($workspace !== 'participating') {
                return;
            }

            $visibleScopes = $event->participationScopes
                ->filter(fn (EventParticipationScope $scope) => $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT
                    && $scope->status === EventParticipationScope::STATUS_ACTIVE
                    && $scope->current_admin_owns_scope)
                ->values();
            $visibleScopeIds = $visibleScopes->pluck('id')->all();

            $event->setRelation('participationScopes', $visibleScopes);
            $event->setRelation('scannerAssignments', $event->scannerAssignments
                ->filter(fn ($assignment) => in_array($assignment->event_participation_scope_id, $visibleScopeIds, true))
                ->values());
            $event->setRelation('scannerSessions', $event->scannerSessions
                ->filter(fn ($session) => in_array($session->event_participation_scope_id, $visibleScopeIds, true))
                ->values());
        });
    }

    private function attendancePolicyLocked(Event $event): bool
    {
        $event->loadMissing('eventDays');
        $timezone = config('services.eaes.event_timezone', 'Asia/Manila');
        $eventStartDate = $event->start_date?->copy()->startOfDay();
        $eventEndDate = $event->end_date?->copy()->startOfDay() ?? $eventStartDate;
        $startsAt = $event->eventDays
            ->filter(function (EventDay $day) use ($eventStartDate, $eventEndDate): bool {
                if (!$eventStartDate || !$eventEndDate || !$day->date) {
                    return true;
                }

                $dayDate = $day->date->copy()->startOfDay();

                return $dayDate->betweenIncluded($eventStartDate, $eventEndDate);
            })
            ->map(function (EventDay $day) use ($timezone): ?Carbon {
                if (!$day->date || !$day->start_time) {
                    return null;
                }

                return Carbon::parse($day->date->toDateString() . ' ' . $day->start_time, $timezone);
            })
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->first();

        if (!$startsAt && $event->start_date) {
            $firstStartTime = $event->eventDays
                ->sortBy('day_number')
                ->first(fn (EventDay $day) => filled($day->start_time))
                ?->start_time;

            if ($firstStartTime) {
                $startsAt = Carbon::parse($event->start_date->toDateString() . ' ' . $firstStartTime, $timezone);
            }
        }

        if (!$startsAt && $event->start_date) {
            $startsAt = Carbon::parse($event->start_date->toDateString(), $timezone)->startOfDay();
        }

        if ($startsAt) {
            return Carbon::now($timezone)->greaterThanOrEqualTo($startsAt);
        }

        return in_array($event->lifecycle_phase, [
            'active',
            'ended',
            'processing',
            'evaluation_open',
            'evaluation_closed',
            'completed',
        ], true);
    }

    private function ensureOrganizerScopesForDashboard($events): void
    {
        $events->each(function (Event $event): void {
            $hasOrganizerScope = $event->participationScopes
                ->contains('participation_role', EventParticipationScope::ROLE_ORGANIZER);

            if ($hasOrganizerScope) {
                return;
            }

            $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']));
            $event->load([
                'participationScopes.organization',
                'participationScopes.college',
                'participationScopes.delegatedOrganization',
            ]);
        });
    }

    private function adminScopeMatchesParticipantScope(?AdminScope $adminScope, EventParticipationScope $scope): bool
    {
        if (!$adminScope) {
            return false;
        }

        if ($adminScope->isSuperAdmin()) {
            return true;
        }

        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
        }

        if ($scope->delegatedOrganization && $adminScope->canAccessOrganization($scope->delegatedOrganization)) {
            return true;
        }

        return $scope->college_id && $adminScope->isLsgAdmin() && $adminScope->collegeId === $scope->college_id;
    }

    private function applyPendingLinkTenantScope($query, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($scope->isSuperAdmin()) return;

        if ($scope->isUsgAdmin()) {
            $query->whereHas('organization', fn($q) => $q->where('type', 'usg')->orWhere('id', $scope->organizationId));
            return;
        }

        if ($scope->isLsgAdmin()) {
            $query->whereHas('organization', fn($q) => $q->where('college_id', $scope->collegeId));
            return;
        }

        if (!$scope->organizationId) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('organization_id', $scope->organizationId);
    }

    private function authorizeEventAccess(Event $event, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            if ($event->status === 'draft') {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        if ($scope->canAccessEvent($event)) {
            return;
        }

        abort(403, $scope->isLsgAdmin()
            ? 'Access denied. LSG Admin restricted to college boundary.'
            : 'Access denied. Restricted to your organization boundary.');
    }

    private function authorizeScannerAssignmentAccess(Event $event, User $user): void
    {
        abort_unless($user->can('assign-scanners-own'), 403);

        $this->authorizeEventAccess($event, $user);
    }

    private function authorizeScannerScopeAccess(User $user, EventParticipationScope $participationScope): void
    {
        abort_unless($user->can('assign-scanners-own'), 403);

        if ($this->participationScopes->canManageScope($user, $participationScope)) {
            return;
        }

        abort(403, 'Access denied. Restricted to scanner scopes you manage.');
    }

    private function authorizeParticipantScopeAccess(Event $event, EventParticipationScope $participationScope, User $user): void
    {
        abort_unless($participationScope->event_id === $event->id, 404);

        if ($this->participationScopes->canManageScope($user, $participationScope)) {
            return;
        }

        abort(403, 'Access denied. Restricted to the participant scope owner or event organizer.');
    }

    private function syncParticipantScopeFromLinkedEvent(Event $parentEvent, Event $linkedEvent): EventParticipationScope
    {
        $linkedEvent->loadMissing('organization');
        $parentScope = $this->participationScopes->ensureOrganizerScope($parentEvent);
        $organization = $linkedEvent->organization;

        return EventParticipationScope::updateOrCreate(
            [
                'event_id' => $parentEvent->id,
                'linked_event_id' => $linkedEvent->id,
            ],
            [
                'parent_scope_id' => $parentScope->id,
                'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
                'participant_type' => $this->participationScopes->participantTypeForOrganization($organization),
                'organization_id' => $organization?->id,
                'college_id' => $organization?->college_id,
                'participant_name_snapshot' => $this->participationScopes->participantName($organization, 'Participant'),
                'ppa_required' => true,
                'ppa_status' => $this->participationScopes->ppaStatusFromEventStatus($linkedEvent->status),
                'ppa_softcopy_path' => $linkedEvent->proposal_document_path,
                'ppa_softcopy_original_name' => $linkedEvent->proposal_document_original_name,
                'hardcopy_submitted' => (bool) $linkedEvent->hardcopy_submitted,
                'org_head_signed' => (bool) $linkedEvent->head_organization_signed,
                'adviser_signed' => (bool) $linkedEvent->adviser_signed,
                'late_cutoff_min' => $linkedEvent->event_late_cutoff_min,
                'approved_at' => in_array($linkedEvent->status, ['approved', 'completed'], true) ? now() : null,
                'capture_mode' => 'self_scanning',
                'analytics_rollup_enabled' => true,
                'status' => EventParticipationScope::STATUS_ACTIVE,
            ]
        );
    }

    private function authorizePendingLinkAccess(PendingStudentLink $pendingLink, User $user): void
    {
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) return;

        if ($pendingLink->participationScope && $this->participationScopes->canViewScope($user, $pendingLink->participationScope)) {
            return;
        }

        $targetOrg = $pendingLink->organization;
        if (!$scope->canAccessOrganization($targetOrg)) {
            abort(403, $scope->isLsgAdmin()
                ? 'Access denied. LSG Admin restricted to college boundary.'
                : 'Access denied. Restricted to your organization boundary.');
        }
    }

    private function canResolveStudentForPendingLink(PendingStudentLink $pendingLink, User $student, User $admin): bool
    {
        if ($pendingLink->participationScope && $this->participationScopes->userBelongsToScope($student, $pendingLink->participationScope)) {
            return true;
        }

        if ($student->organization_id === $pendingLink->organization_id && !$pendingLink->event_participation_scope_id) {
            return true;
        }

        $scope = $this->adminScopes->forUser($admin);

        if ($scope?->isSuperAdmin()) {
            return true;
        }

        return false;
    }
}
