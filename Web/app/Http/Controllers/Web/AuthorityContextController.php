<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectAuthorityContextRequest;
use App\Services\AdminScopeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthorityContextController extends Controller
{
    public function __construct(private readonly AdminScopeService $adminScopes) {}

    public function index(Request $request): View|RedirectResponse
    {
        $assignments = $this->adminScopes->availableAssignmentsFor($request->user());

        if ($assignments->count() === 1) {
            $request->session()->put(AdminScopeService::SESSION_KEY, $assignments->first()->id);

            return redirect()->intended(route('dashboard'));
        }

        return view('dashboard.authority-context', [
            'authorityContexts' => $assignments,
        ]);
    }

    public function store(SelectAuthorityContextRequest $request): RedirectResponse
    {
        $assignmentId = $request->validated('assignment_id');
        $assignment = $this->adminScopes
            ->availableAssignmentsFor($request->user())
            ->firstWhere('id', $assignmentId);

        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Select an active authority context assigned to your account.',
            ]);
        }

        $request->session()->put(AdminScopeService::SESSION_KEY, $assignment->id);

        return redirect()->intended(route('dashboard'))
            ->with('success', "Authority context changed to {$assignment->organization?->name}.");
    }
}
