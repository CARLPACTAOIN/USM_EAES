<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Services\AdminScopeService;
use App\Services\ParticipationScopeService;
use App\Support\EventEvaluationForm;
use App\Support\EventTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EventProposalController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes
    ) {
    }

    /**
     * Display a listing of events scoped by user organization/college tenancy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Event::with(['organization', 'eventDays']);
        $scope = $this->adminScopes->forUser($user);

        if (!$scope) {
            abort(403, 'No active admin assignment found.');
        }

        // Tenancy constraints based on active admin assignment scope.
        if ($scope->isSuperAdmin()) {
            // OSA reviews submitted proposals and history; drafts stay organizer-private.
            $query->where('status', '!=', 'draft');
        } elseif ($scope->isUsgAdmin()) {
            // USG Admin sees USG events or their specific organization events
            $query->whereHas('organization', function ($q) use ($scope) {
                $q->where('type', 'usg')->orWhere('id', $scope->organizationId);
            });
        } elseif ($scope->isLsgAdmin()) {
            // LSG Admin sees events within their college boundary
            if ($scope->collegeId) {
                $query->whereHas('organization', function ($q) use ($scope) {
                    $q->where('college_id', $scope->collegeId);
                });
            } else {
                return response()->json(['success' => false, 'message' => 'Admin assignment college scope not found.'], 400);
            }
        } else {
            // Society admins see only their assignment organization events.
            $query->where('organization_id', $scope->organizationId);
        }

        $events = $query->latest()->get();

        return response()->json([
            'success' => true,
            'events' => $events
        ]);
    }

    /**
     * Store a newly created event proposal in storage (default status 'draft').
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_type' => 'required|in:on-campus,off-campus',
            'location_details' => 'nullable|string',
            'target_demographics' => 'nullable|array',
            'budget_allocations' => 'nullable|array',
            'event_late_cutoff_min' => 'nullable|integer|min:0|max:240',
            'event_days' => 'required|array|min:1',
            'event_days.*.day_number' => 'required|integer|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i|after:event_days.*.start_time',
            'parent_event_id' => 'nullable|uuid|exists:events,id',
            'evaluation_questions' => 'required|array|min:1|max:10',
            'evaluation_questions.*.id' => 'nullable|string|max:80',
            'evaluation_questions.*.label' => 'required|string|max:180',
            'evaluation_questions.*.description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $scope = $this->adminScopes->forUser($user);
            $organizationId = $scope?->isSuperAdmin()
                ? $request->input('organization_id')
                : $scope?->organizationId;

            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'An active admin organization scope is required to create an event proposal.',
                ], 403);
            }

            $organization = Organization::find($organizationId);
            if (!$organization || !in_array($organization->type, ['society', 'usg', 'lsg', 'academic_unit', 'administrative_office', 'non_academic_org', 'hr_unit'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'An active recognized organizer is required to create an event proposal.',
                ], 422);
            }

            $isHrEvent = $organization->type === 'hr_unit';

            // Create the Event
            $event = Event::create([
                'organization_id' => $organizationId,
                'parent_event_id' => $request->parent_event_id,
                'title' => $request->title,
                'event_domain' => $organization->type === 'hr_unit' ? 'hr' : 'student_affairs',
                'status' => $isHrEvent ? 'approved' : 'draft',
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'location_type' => $request->location_type,
                'location_details' => $request->location_details,
                'target_demographics' => $request->target_demographics,
                'budget_allocations' => $request->budget_allocations,
                'event_late_cutoff_min' => $request->filled('event_late_cutoff_min')
                    ? (int) $request->input('event_late_cutoff_min')
                    : null,
            ]);

            // Create Event Days
            foreach ($request->event_days as $dayData) {
                EventDay::create([
                    'event_id' => $event->id,
                    'day_number' => $dayData['day_number'],
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

            return response()->json([
                'success' => true,
                'message' => $isHrEvent
                    ? 'HR attendance event created and approved for scanner access.'
                    : 'Event PPA proposal created as draft.',
                'event' => $event->load('eventDays', 'evaluationFormVersion')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create event proposal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit PPA proposal for review.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        if ($event->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft proposals can be submitted.'
            ], 422);
        }

        $event->status = 'submitted';
        $event->save();
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'submitted_by' => request()->user()?->id,
            'submitted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully submitted for review.',
            'event' => $event
        ]);
    }

    /**
     * transition event to under_review status (OSA action).
     */
    public function startReview($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        if ($event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Only submitted proposals can be reviewed.'
            ], 422);
        }

        $event->status = 'under_review';
        $event->save();
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => request()->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Proposal is now under review.',
            'event' => $event
        ]);
    }

    /**
     * Approve PPA proposal (OSA action).
     */
    public function approve($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        if ($event->status !== 'under_review' && $event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Proposals must be submitted or under review to be approved.'
            ], 422);
        }

        if (!EventEvaluationForm::hasLockedForm($event)) {
            return response()->json([
                'success' => false,
                'message' => 'A locked evaluation form with at least one question is required before approval.'
            ], 422);
        }

        $event->status = 'approved';
        $event->save();
        $this->participationScopes->ensureOrganizerScope($event->fresh())->update([
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => request()->user()?->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully approved. Scanning is now unlocked.',
            'event' => $event
        ]);
    }

    /**
     * Reject PPA proposal (OSA action).
     */
    public function reject($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        if ($event->status !== 'under_review' && $event->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Proposals must be submitted or under review to be rejected.'
            ], 422);
        }

        $event->status = 'rejected';
        $event->save();
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => request()->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal was rejected.',
            'event' => $event
        ]);
    }

    /**
     * Link a sub-organization PPA to a parent event (Multi-Org Event Linking).
     */
    public function linkSubEvent(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, $request->user());

        $validator = Validator::make($request->all(), [
            'parent_event_id' => 'required|uuid|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $parentEvent = Event::findOrFail($request->parent_event_id);

        // Verify the parent event belongs to a campus-wide organizer
        $parentOrg = $parentEvent->organization;
        if (!in_array($parentOrg->type, ['usg', 'lsg', 'academic_unit', 'administrative_office'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sub-events can only be linked to USG or LSG parent events.'
            ], 422);
        }

        $event->parent_event_id = $request->parent_event_id;
        $event->save();
        $this->syncParticipantScopeFromLinkedEvent($parentEvent, $event->fresh(['organization']));

        return response()->json([
            'success' => true,
            'message' => 'Event successfully linked under the parent permit.',
            'event' => $event->load('parentEvent')
        ]);
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
}
