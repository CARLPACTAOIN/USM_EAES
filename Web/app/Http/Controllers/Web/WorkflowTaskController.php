<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideWorkflowTaskRequest;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Services\ProposalWorkflowTaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkflowTaskController extends Controller
{
    public function __construct(private readonly ProposalWorkflowTaskService $tasks) {}

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
            'eligibleReplacements' => $request->user()->hasRole('Super Admin (OSA)')
                ? $this->tasks->eligibleReplacements($task)->reject(fn (User $user): bool => $user->id === $task->assigned_user_id)
                : collect(),
        ]);
    }

    public function reassign(Request $request, ProposalWorkflowTask $task): RedirectResponse
    {
        $validated = $request->validate([
            'new_assignee_id' => ['required', 'uuid', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ], [], [
            'new_assignee_id' => 'replacement approver',
        ]);

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

        $this->tasks->decide(
            $task,
            $request->user(),
            $validated['decision'],
            $validated['remarks'] ?? null
        );

        return redirect()
            ->route('dashboard.workflow-tasks.show', $task)
            ->with('success', 'Workflow task decision recorded in the Approval Trail.');
    }
}
