<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizerProposalDraftRequest;
use App\Models\Event;
use App\Services\EventProposalSubmissionGuard;
use App\Services\OrganizerProposalDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerProposalDraftController extends Controller
{
    public function __construct(
        private readonly OrganizerProposalDraftService $drafts,
        private readonly EventProposalSubmissionGuard $guard,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $event = $this->drafts->start($request->user());

        return response()->json([
            'event' => $this->payload($event),
            'readiness' => $this->guard->readiness($event),
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $viewEvent = $this->drafts->view($event, $request->user());

        return response()->json([
            'event' => $this->payload($viewEvent),
            'readiness' => $this->guard->readiness($viewEvent),
        ]);
    }

    public function update(UpdateOrganizerProposalDraftRequest $request, Event $event): JsonResponse
    {
        $savedEvent = $this->drafts->save($event, $request->user(), $request->validated());

        return response()->json([
            'event' => $this->payload($savedEvent),
            'readiness' => $this->guard->readiness($savedEvent),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        $event->loadMissing(['canonicalPpaPacket.attachments', 'venue']);

        return [
            'id' => $event->id,
            'organization_id' => $event->organization_id,
            'school_year_id' => $event->school_year_id,
            'status' => $event->status,
            'title' => $event->title,
            'proposal_category' => $event->proposal_category,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'location_type' => $event->location_type,
            'location_details' => $event->location_details,
            'venue_id' => $event->venue_id,
            'venue_confirmation_office' => $event->venue_confirmation_office,
            'venue_confirmation_date' => $event->venue_confirmation_date?->toDateString(),
            'venue_confirmation_reference_number' => $event->venue_confirmation_reference_number,
            'resolution_number' => $event->resolution_number,
            'canonical_ppa_packet' => $event->canonicalPpaPacket?->only([
                'id',
                'activity_role',
                'title',
                'proposal_category',
                'proponent_name',
                'proponent_position',
                'proponent_organization',
                'proponent_email',
                'proponent_contact_number',
                'implementing_office',
                'collaborating_office',
                'source_of_fund',
                'budget_cost',
                'start_date',
                'end_date',
                'location_type',
                'venue_id',
                'location_details',
                'venue_confirmation_office',
                'venue_confirmation_date',
                'venue_confirmation_reference_number',
                'rationale',
                'general_objective',
                'specific_objectives',
                'methodology',
                'major_activities',
                'program_activities',
                'expected_output',
                'beneficiaries',
                'delegate_count',
                'budget_breakdown',
                'schedule_summary',
                'venue_summary',
                'prepared_by_user_id',
            ]),
            'attachments' => $event->canonicalPpaPacket?->attachments?->map(fn ($a) => [
                'id' => $a->id,
                'attachment_type' => $a->attachment_type,
                'original_name' => $a->original_name,
                'size_bytes' => $a->size_bytes,
                'mime_type' => $a->mime_type,
            ])->values()->all() ?? [],
        ];
    }
}
