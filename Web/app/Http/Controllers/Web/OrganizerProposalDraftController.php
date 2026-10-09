<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\OrganizerProposalDraftService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizerProposalDraftController extends Controller
{
    public function __construct(private readonly OrganizerProposalDraftService $drafts) {}

    public function start(Request $request): RedirectResponse
    {
        $event = $this->drafts->start($request->user());

        return redirect()->route('dashboard.proposal-drafts.edit', $event);
    }

    public function edit(Request $request, Event $event): View
    {
        return view('dashboard.events.proposal-draft-editor', [
            'event' => $this->drafts->view($event, $request->user()),
        ]);
    }

    public function save(Request $request, Event $event): RedirectResponse
    {
        $this->drafts->save($event, $request->user(), $request->validate($this->rules()));

        return redirect()->route('dashboard.proposal-drafts.edit', $event)
            ->with('success', 'Draft saved.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'proposal_category' => ['sometimes', 'nullable', 'in:program,project,activity'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'location_type' => ['sometimes', 'nullable', 'in:on-campus,off-campus,online'],
            'location_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_id' => ['sometimes', 'nullable', 'uuid', 'exists:venues,id'],
            'venue_confirmation_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'venue_confirmation_date' => ['sometimes', 'nullable', 'date'],
            'venue_confirmation_reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target_demographics' => ['sometimes', 'nullable', 'array'],
            'budget_allocations' => ['sometimes', 'nullable', 'array'],
            'resolution_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'event_days' => ['sometimes', 'nullable', 'array'],
            'evaluation_questions' => ['sometimes', 'nullable', 'array'],
            'canonical_packet' => ['sometimes', 'nullable', 'array'],
            'canonical_packet.activity_role' => ['sometimes', 'nullable', 'in:organizer,co-organizer'],
            'canonical_packet.proponent_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'canonical_packet.proponent_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'canonical_packet.proponent_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'canonical_packet.rationale' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.general_objective' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'canonical_packet.specific_objectives' => ['sometimes', 'nullable'],
            'canonical_packet.methodology' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.major_activities' => ['sometimes', 'nullable'],
            'canonical_packet.expected_output' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'canonical_packet.beneficiaries' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
