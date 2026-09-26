<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalProcessTemplateRequest;
use App\Http\Requests\UpdateProposalProcessTemplateVersionRequest;
use App\Models\College;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Services\OrganizationManagementService;
use App\Services\ProposalProcessTemplateManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProposalProcessTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeOsa($request);

        $templates = ProposalProcessTemplate::query()
            ->with([
                'creator',
                'versions' => fn ($query) => $query
                    ->withCount('workflowRuns')
                    ->with(['publisher', 'retirer', 'steps.prerequisiteSteps', 'applicabilityRules.college'])
                    ->orderByDesc('version_number'),
            ])
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('dashboard.proposal-process-templates.index', [
            'templates' => $templates,
            'colleges' => College::query()->orderBy('name')->get(),
            'stepTypes' => $this->stepTypes(),
            'organizationTypes' => OrganizationManagementService::ORGANIZATION_TYPES,
        ]);
    }

    public function store(
        StoreProposalProcessTemplateRequest $request,
        ProposalProcessTemplateManagementService $service
    ): RedirectResponse {
        $version = $service->create($request->validated(), $request->user());

        return redirect()
            ->route('dashboard.proposal-process-templates.index', ['version' => $version->id])
            ->with('success', 'Proposal process template draft created.');
    }

    public function update(
        UpdateProposalProcessTemplateVersionRequest $request,
        ProposalProcessTemplateVersion $version,
        ProposalProcessTemplateManagementService $service
    ): RedirectResponse {
        try {
            $version = $service->updateDraft($version, $request->validated(), $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors())
                ->withInput();
        }

        return redirect()
            ->route('dashboard.proposal-process-templates.index', ['version' => $version->id])
            ->with('success', 'Proposal process template draft updated.');
    }

    public function publish(
        Request $request,
        ProposalProcessTemplateVersion $version,
        ProposalProcessTemplateManagementService $service
    ): RedirectResponse {
        $this->authorizeOsa($request);

        try {
            $version = $service->publish($version, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('dashboard.proposal-process-templates.index', ['version' => $version->id])
            ->with('success', 'Proposal process template version published and locked.');
    }

    public function retire(
        Request $request,
        ProposalProcessTemplateVersion $version,
        ProposalProcessTemplateManagementService $service
    ): RedirectResponse {
        $this->authorizeOsa($request);

        try {
            $version = $service->retire($version, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->back()
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('dashboard.proposal-process-templates.index', ['version' => $version->id])
            ->with('success', 'Proposal process template version retired.');
    }

    public function createDraft(
        Request $request,
        ProposalProcessTemplateVersion $version,
        ProposalProcessTemplateManagementService $service
    ): RedirectResponse {
        $this->authorizeOsa($request);

        $draft = $service->createDraftFrom($version, $request->user());

        return redirect()
            ->route('dashboard.proposal-process-templates.index', ['version' => $draft->id])
            ->with('success', 'New draft version created from the locked template version.');
    }

    private function authorizeOsa(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Super Admin (OSA)'), 403);
    }

    /**
     * @return array<int, string>
     */
    private function stepTypes(): array
    {
        return [
            ProposalProcessStep::TYPE_INTAKE,
            ProposalProcessStep::TYPE_ENDORSEMENT,
            ProposalProcessStep::TYPE_REVIEW,
            ProposalProcessStep::TYPE_CLEARANCE,
            ProposalProcessStep::TYPE_APPROVAL,
        ];
    }
}
