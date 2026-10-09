<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizerProposalDraftRequest;
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

    public function save(UpdateOrganizerProposalDraftRequest $request, Event $event): RedirectResponse
    {
        $this->drafts->save($event, $request->user(), $request->validated());

        return redirect()->route('dashboard.proposal-drafts.edit', $event)
            ->with('success', 'Draft saved.');
    }
}
