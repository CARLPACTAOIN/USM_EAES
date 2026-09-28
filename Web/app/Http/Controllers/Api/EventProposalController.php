<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\AdminScopeService;
use App\Services\EventNotificationService;
use App\Services\EventPermitService;
use App\Services\EventProposalDraftService;
use App\Services\EventSessionScheduleService;
use App\Services\ParticipationScopeService;
use App\Support\EventTenantScope;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EventProposalController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventNotificationService $eventNotifications,
        private readonly EventProposalDraftService $proposalDrafts,
        private readonly EventPermitService $eventPermits,
        private readonly EventSessionScheduleService $sessionSchedules,
    ) {}

    /**
     * Display a listing of events scoped by user organization/college tenancy.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Event::with(['canonicalPpaPacket', 'organization', 'eventDays.sessions', 'venue']);
        $scope = $this->adminScopes->forUser($user);

        if (! $scope) {
            abort(403, 'No active admin assignment found.');
        }

        // Tenancy constraints based on active admin assignment scope.
        if ($scope->isSuperAdmin()) {
            // OSA reviews submitted proposals and history; drafts stay organizer-private.
            $query->where('status', '!=', 'draft');
        } elseif ($scope->organizationId) {
            $query->where('organization_id', $scope->organizationId);
        } else {
            return response()->json(['success' => false, 'message' => 'Admin assignment organization scope not found.'], 400);
        }

        $events = $query->latest()->get();

        return response()->json([
            'success' => true,
            'events' => $events->map(fn (Event $event): array => $this->eventPayload($event)),
        ]);
    }

    /**
     * Store a newly created event proposal in storage (default status 'draft').
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'organization_id' => 'nullable|uuid|exists:organizations,id',
            'title' => 'required|string|max:255',
            'proposal_category' => 'required|in:program,project,activity',
            'activity_role' => 'nullable|in:organizer,co-organizer',
            'canonical_packet' => 'nullable|array',
            'canonical_packet.activity_role' => 'nullable|in:organizer,co-organizer',
            'canonical_packet.proponent_name' => 'nullable|string|max:255',
            'canonical_packet.proponent_position' => 'nullable|string|max:255',
            'canonical_packet.proponent_organization' => 'nullable|string|max:255',
            'canonical_packet.proponent_email' => 'nullable|email|max:255',
            'canonical_packet.proponent_contact_number' => 'nullable|string|max:50',
            'canonical_packet.rationale' => 'nullable|string|max:5000',
            'canonical_packet.general_objective' => 'nullable|string|max:2000',
            'canonical_packet.specific_objectives' => 'nullable',
            'canonical_packet.methodology' => 'nullable|string|max:5000',
            'canonical_packet.major_activities' => 'nullable',
            'canonical_packet.expected_output' => 'nullable|string|max:5000',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_type' => 'required|in:on-campus,off-campus,online',
            'location_details' => 'nullable|string',
            'venue_id' => 'nullable|uuid|exists:venues,id',
            'venue_confirmation_office' => 'nullable|string|max:255',
            'venue_confirmation_date' => 'nullable|date|before_or_equal:today',
            'venue_confirmation_reference_number' => 'nullable|string|max:255',
            'venue_confirmation_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'target_demographics' => 'nullable|array',
            'budget_allocations' => 'nullable|array',
            'resolution_number' => 'nullable|string|max:100',
            'proposal_document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'hardcopy_submitted' => 'nullable|boolean',
            'head_organization_signed' => 'nullable|boolean',
            'adviser_signed' => 'nullable|boolean',
            'event_days' => 'required|array|min:1',
            'event_days.*.day_number' => 'nullable|integer|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i|after:event_days.*.start_time',
            'event_days.*.sessions' => 'nullable|array|min:1',
            'event_days.*.sessions.*.name' => 'nullable|string|max:80',
            'event_days.*.sessions.*.start_time' => 'required|date_format:H:i',
            'event_days.*.sessions.*.end_time' => 'required|date_format:H:i',
            'event_days.*.sessions.*.required_for_attendance' => 'nullable|boolean',
            'parent_event_id' => 'nullable|uuid|exists:events,id',
            'evaluation_questions' => 'required|array|min:1|max:10',
            'evaluation_questions.*.id' => 'nullable|string|max:80',
            'evaluation_questions.*.label' => 'required|string|max:180',
            'evaluation_questions.*.description' => 'nullable|string|max:500',
        ]);

        $this->addSessionScheduleValidation($validator, (array) $request->input('event_days', []));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $scope = $this->adminScopes->forUser($user);
        if ($scope && ! $scope->isSuperAdmin() && $request->filled('organization_id') && $request->input('organization_id') !== $scope->organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Event proposals can only be created within your active organization scope.',
            ], 403);
        }

        $organizationId = $scope?->isSuperAdmin()
            ? $request->input('organization_id')
            : $scope?->organizationId;

        if (! $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'An active admin organization scope is required to create an event proposal.',
            ], 403);
        }

        try {
            $event = $this->proposalDrafts->create(
                $user,
                $this->proposalData($request, $organizationId),
                $request->file('proposal_document'),
                $request->file('venue_confirmation_document')
            );

            return response()->json([
                'success' => true,
                'message' => $event->event_domain === 'hr'
                    ? 'HR attendance event created and approved for scanner access.'
                    : 'Event PPA proposal created as draft.',
                'event' => $this->eventPayload($event),
            ], 201);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to create event proposal.',
            ], 500);
        }
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        EventTenantScope::authorize($event, $request->user());
        $scope = $this->adminScopes->forUser($request->user());

        if ($request->user()?->hasRole('Super Admin (OSA)') || $scope?->organizationId !== $event->organization_id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the event organizer can edit this proposal.',
            ], 403);
        }

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REVISION_REQUESTED], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or revision-requested proposals can be edited.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'proposal_category' => 'required|in:program,project,activity',
            'activity_role' => 'nullable|in:organizer,co-organizer',
            'canonical_packet' => 'nullable|array',
            'canonical_packet.activity_role' => 'nullable|in:organizer,co-organizer',
            'canonical_packet.proponent_name' => 'nullable|string|max:255',
            'canonical_packet.proponent_position' => 'nullable|string|max:255',
            'canonical_packet.proponent_organization' => 'nullable|string|max:255',
            'canonical_packet.proponent_email' => 'nullable|email|max:255',
            'canonical_packet.proponent_contact_number' => 'nullable|string|max:50',
            'canonical_packet.rationale' => 'nullable|string|max:5000',
            'canonical_packet.general_objective' => 'nullable|string|max:2000',
            'canonical_packet.specific_objectives' => 'nullable',
            'canonical_packet.methodology' => 'nullable|string|max:5000',
            'canonical_packet.major_activities' => 'nullable',
            'canonical_packet.expected_output' => 'nullable|string|max:5000',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'location_type' => 'required|in:on-campus,off-campus,online',
            'location_details' => 'nullable|string',
            'venue_id' => 'nullable|uuid|exists:venues,id',
            'venue_confirmation_office' => 'nullable|string|max:255',
            'venue_confirmation_date' => 'nullable|date|before_or_equal:today',
            'venue_confirmation_reference_number' => 'nullable|string|max:255',
            'venue_confirmation_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'target_demographics' => 'nullable|array',
            'budget_allocations' => 'nullable|array',
            'resolution_number' => 'nullable|string|max:100',
            'proposal_document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'hardcopy_submitted' => 'nullable|boolean',
            'head_organization_signed' => 'nullable|boolean',
            'adviser_signed' => 'nullable|boolean',
            'event_days' => 'required|array|min:1',
            'event_days.*.day_number' => 'nullable|integer|min:1',
            'event_days.*.date' => 'required|date',
            'event_days.*.start_time' => 'required|date_format:H:i',
            'event_days.*.end_time' => 'required|date_format:H:i',
            'event_days.*.sessions' => 'nullable|array|min:1',
            'event_days.*.sessions.*.name' => 'nullable|string|max:80',
            'event_days.*.sessions.*.start_time' => 'required|date_format:H:i',
            'event_days.*.sessions.*.end_time' => 'required|date_format:H:i',
            'event_days.*.sessions.*.required_for_attendance' => 'nullable|boolean',
            'parent_event_id' => 'nullable|uuid|exists:events,id',
            'evaluation_questions' => 'required|array|min:1|max:10',
            'evaluation_questions.*.id' => 'nullable|string|max:80',
            'evaluation_questions.*.label' => 'required|string|max:180',
            'evaluation_questions.*.description' => 'nullable|string|max:500',
        ]);

        $validator->after(function ($validator) use ($request, $event): void {
            if ($request->filled('parent_event_id') && $request->input('parent_event_id') === $event->id) {
                $validator->errors()->add('parent_event_id', 'A proposal cannot be linked to itself as a parent permit.');
            }

            try {
                $this->sessionSchedules->normalizeEventDays((array) $request->input('event_days', []));
            } catch (DomainException $exception) {
                $validator->errors()->add('event_days', $exception->getMessage());
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $updatedEvent = $this->proposalDrafts->update(
                $event,
                $request->user(),
                $this->proposalData($request, $event->organization_id),
                $request->file('proposal_document'),
                $request->file('venue_confirmation_document')
            );

            return response()->json([
                'success' => true,
                'message' => 'Event PPA proposal updated.',
                'event' => $this->eventPayload($updatedEvent),
            ]);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to update event proposal.',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalData(Request $request, string $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'parent_event_id' => $request->filled('parent_event_id') ? $request->string('parent_event_id')->toString() : null,
            'title' => $request->string('title')->toString(),
            'proposal_category' => $request->string('proposal_category')->toString(),
            'canonical_packet' => array_merge(
                ['activity_role' => $request->input('activity_role', 'organizer')],
                (array) $request->input('canonical_packet', [])
            ),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'location_type' => $request->string('location_type')->toString(),
            'location_details' => $request->input('location_details'),
            'venue_id' => $request->input('venue_id'),
            'venue_confirmation_office' => $request->input('venue_confirmation_office'),
            'venue_confirmation_date' => $request->input('venue_confirmation_date'),
            'venue_confirmation_reference_number' => $request->input('venue_confirmation_reference_number'),
            'target_demographics' => $request->input('target_demographics', []),
            'budget_allocations' => $request->input('budget_allocations', []),
            'resolution_number' => $request->input('resolution_number'),
            'event_days' => collect($request->input('event_days', []))
                ->map(fn (array $day): array => [
                    'date' => $day['date'],
                    'start_time' => $day['start_time'],
                    'end_time' => $day['end_time'],
                    'sessions' => $day['sessions'] ?? [],
                ])
                ->all(),
            'evaluation_questions' => $request->input('evaluation_questions', []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventDays
     */
    private function addSessionScheduleValidation(mixed $validator, array $eventDays): void
    {
        try {
            $this->sessionSchedules->normalizeEventDays($eventDays);
        } catch (DomainException $exception) {
            $validator->errors()->add('event_days', $exception->getMessage());
        }
    }

    /**
     * Submit PPA proposal for review.
     *
     * @param  string  $id
     * @return JsonResponse
     */
    public function submit($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        try {
            $event = $this->eventPermits->submit($event, request()->user(), request()->boolean('returned_tasks_addressed'));
            $this->eventNotifications->notifyPendingInvitationsForEvent($event->fresh(['organization', 'participationScopes.organization', 'participationScopes.delegatedOrganization']));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully submitted for review.',
            'event' => $this->eventPayload($event),
        ]);
    }

    /**
     * transition event to under_review status (OSA action).
     */
    public function startReview($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        try {
            $event = $this->eventPermits->startReview($event, request()->user());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proposal is now under review.',
            'event' => $this->eventPayload($event),
        ]);
    }

    /**
     * Approve PPA proposal (OSA action).
     */
    public function approve($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        try {
            $event = $this->eventPermits->approve($event, request()->user());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal successfully approved. Scanning is now unlocked.',
            'event' => $this->eventPayload($event),
        ]);
    }

    public function requestRevision(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, $request->user());

        $validator = Validator::make($request->all(), [
            'review_remarks' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $event = $this->eventPermits->requestRevision($event, $request->user(), $request->input('review_remarks'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal was returned for revision.',
            'event' => $this->eventPayload($event),
        ]);
    }

    /**
     * Reject PPA proposal (OSA action).
     */
    public function reject($id)
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, request()->user());

        try {
            $event = $this->eventPermits->reject($event, request()->user());
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event PPA proposal was rejected.',
            'event' => $this->eventPayload($event),
        ]);
    }

    public function startVenueAmendment(Request $request, Event $event): JsonResponse
    {
        EventTenantScope::authorize($event, $request->user());
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $event = $this->eventPermits->beginVenueAmendment($event, $request->user(), $validated['reason']);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Venue amendment started. Scanner access is locked until OSA approval.',
            'event' => $this->eventPayload($event),
        ]);
    }

    /** @return array<string, mixed> */
    private function eventPayload(Event $event): array
    {
        $event->loadMissing(['canonicalPpaPacket', 'organization', 'eventDays.sessions', 'venue']);
        $attributes = $event->toArray();
        unset($attributes['proposal_document_path'], $attributes['venue_confirmation_document_path']);

        return [
            ...$attributes,
            'venue' => $event->venue ? [
                'id' => $event->venue->id,
                'name' => $event->venue->name,
                'campus_location' => $event->venue->campus_location,
                'managing_office' => $event->venue->managing_office,
                'inquiry_instructions' => $event->venue->inquiry_instructions,
            ] : null,
            'venue_confirmation' => [
                'office' => $event->venue_confirmation_office,
                'date' => $event->venue_confirmation_date?->toDateString(),
                'reference_number' => $event->venue_confirmation_reference_number,
                'evidence_available' => filled($event->venue_confirmation_document_path),
            ],
            'permit_version' => $event->permit_version,
            'amendment_pending' => filled($event->pending_venue_amendment_reason),
            'canonical_ppa_packet' => $event->canonicalPpaPacket?->makeHidden(['created_at', 'updated_at'])->toArray(),
        ];
    }

    /**
     * Link a sub-organization PPA to a parent event (Multi-Org Event Linking).
     */
    public function linkSubEvent(Request $request, string $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        EventTenantScope::authorize($event, $request->user());

        $validator = Validator::make($request->all(), [
            'parent_event_id' => 'required|uuid|exists:events,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $parentEvent = Event::findOrFail($request->parent_event_id);

        // Verify the parent event belongs to a campus-wide organizer
        $parentOrg = $parentEvent->organization;
        if (! in_array($parentOrg->type, ['usg', 'lsg', 'academic_unit', 'administrative_office'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sub-events can only be linked to USG or LSG parent events.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($event, $parentEvent): void {
                $event->update(['parent_event_id' => $parentEvent->id]);
                $this->participationScopes->syncParticipantScopeFromLinkedEvent($parentEvent, $event);
            });
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event successfully linked under the parent permit.',
            'event' => $event->load('parentEvent'),
        ]);
    }
}
