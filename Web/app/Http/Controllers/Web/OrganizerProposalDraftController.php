<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizerProposalDraftRequest;
use App\Models\Event;
use App\Models\Venue;
use App\Services\EventProposalSubmissionGuard;
use App\Services\OrganizerProposalDraftService;
use App\Support\EvaluationQuestions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizerProposalDraftController extends Controller
{
    public function __construct(
        private readonly OrganizerProposalDraftService $drafts,
        private readonly EventProposalSubmissionGuard $guard,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $event = $this->drafts->start($request->user());

        return redirect()->route('dashboard.proposal-drafts.edit', $event);
    }

    public function edit(Request $request, Event $event): View
    {
        $eventData = $this->drafts->view($event, $request->user());
        $readiness = $this->guard->readiness($eventData);
        $venues = Venue::active()->orderBy('name')->get();
        $defaultEvaluationQuestions = EvaluationQuestions::defaultSchema();

        return view('dashboard.events.proposal-draft-editor', [
            'event' => $eventData,
            'packet' => $eventData->canonicalPpaPacket,
            'readiness' => $readiness,
            'venues' => $venues,
            'defaultEvaluationQuestions' => $defaultEvaluationQuestions,
        ]);
    }

    public function save(UpdateOrganizerProposalDraftRequest $request, Event $event): JsonResponse|RedirectResponse
    {
        $savedEvent = $this->drafts->save($event, $request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Draft saved.',
                'event' => [
                    'id' => $savedEvent->id,
                    'title' => $savedEvent->title,
                    'proposal_category' => $savedEvent->proposal_category,
                    'start_date' => $savedEvent->start_date?->toDateString(),
                    'end_date' => $savedEvent->end_date?->toDateString(),
                    'location_type' => $savedEvent->location_type,
                    'location_details' => $savedEvent->location_details,
                    'venue_id' => $savedEvent->venue_id,
                    'proposal_document_original_name' => $savedEvent->proposal_document_original_name,
                    'venue_confirmation_document_original_name' => $savedEvent->venue_confirmation_document_original_name,
                ],
                'packet' => $savedEvent->canonicalPpaPacket,
                'readiness' => $this->guard->readiness($savedEvent),
            ]);
        }

        return redirect()->route('dashboard.proposal-drafts.edit', $event)
            ->with('success', 'Draft saved.');
    }
}
