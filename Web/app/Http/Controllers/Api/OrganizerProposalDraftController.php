<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizerProposalDraftRequest;
use App\Models\Event;
use App\Services\OrganizerProposalDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerProposalDraftController extends Controller
{
    public function __construct(private readonly OrganizerProposalDraftService $drafts) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'event' => $this->payload($this->drafts->start($request->user())),
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        return response()->json([
            'event' => $this->payload($this->drafts->view($event, $request->user())),
        ]);
    }

    public function update(UpdateOrganizerProposalDraftRequest $request, Event $event): JsonResponse
    {
        return response()->json([
            'event' => $this->payload($this->drafts->save($event, $request->user(), $request->validated())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        $event->loadMissing('canonicalPpaPacket');

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
            'canonical_ppa_packet' => $event->canonicalPpaPacket?->only([
                'id',
                'activity_role',
                'title',
                'proposal_category',
                'proponent_name',
                'proponent_organization',
                'proponent_email',
                'beneficiaries',
                'prepared_by_user_id',
            ]),
        ];
    }
}
