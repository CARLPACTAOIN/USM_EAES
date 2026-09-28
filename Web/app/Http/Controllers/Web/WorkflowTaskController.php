<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideWorkflowTaskRequest;
use App\Http\Requests\ReassignWorkflowTaskRequest;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Services\ProposalWorkflowTaskService;
use App\Support\ProposalRevisionTargets;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkflowTaskController extends Controller
{
    public function __construct(
        private readonly ProposalWorkflowTaskService $tasks,
        private readonly ProposalRevisionTargets $revisionTargets,
    ) {}

    public function index(Request $request): View
    {
        return view('dashboard.workflow-tasks.index', [
            'tasks' => $this->tasks->inboxFor($request->user()),
        ]);
    }

    public function show(Request $request, ProposalWorkflowTask $task): View
    {
        $task = $this->tasks->taskForUserOrFail($request->user(), $task);

        return view('dashboard.workflow-tasks.show', [
            'task' => $task,
            'event' => $task->workflowRun->event,
            'revisionTargetGroups' => $this->revisionTargets->optionsForTask($task),
            'eligibleReplacements' => $request->user()->hasRole('Super Admin (OSA)')
                ? $this->tasks->eligibleReplacements($task)->reject(fn (User $user): bool => $user->id === $task->assigned_user_id)
                : collect(),
        ]);
    }

    public function reassign(ReassignWorkflowTaskRequest $request, ProposalWorkflowTask $task): RedirectResponse
    {
        $validated = $request->validated();

        $this->tasks->reassign(
            $task,
            User::findOrFail($validated['new_assignee_id']),
            $request->user(),
            $validated['reason']
        );

        return redirect()
            ->route('dashboard.workflow-tasks.show', $task)
            ->with('success', 'Workflow task reassigned with assignment history recorded.');
    }

    public function decide(DecideWorkflowTaskRequest $request, ProposalWorkflowTask $task): RedirectResponse
    {
        $validated = $request->validated();
        $revisionTarget = $this->revisionTargetInput($validated);

        $this->tasks->decide(
            $task,
            $request->user(),
            $validated['decision'],
            $validated['remarks'] ?? null,
            $revisionTarget
        );

        return redirect()
            ->route('dashboard.workflow-tasks.show', $task)
            ->with('success', 'Workflow task decision recorded in the Approval Trail.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{type: string|null, key: string|null}
     */
    private function revisionTargetInput(array $validated): array
    {
        if (filled($validated['revision_target'] ?? null)) {
            [$type, $key] = array_pad(explode(':', (string) $validated['revision_target'], 2), 2, null);

            return [
                'type' => $type,
                'key' => $key,
            ];
        }

        return [
            'type' => $validated['revision_target_type'] ?? null,
            'key' => $validated['revision_target_key'] ?? null,
        ];
    }
}
