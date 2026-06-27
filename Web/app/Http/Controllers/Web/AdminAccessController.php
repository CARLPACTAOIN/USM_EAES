<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminApplication;
use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminAccessController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOsa($request);

        $pendingApplications = AdminApplication::with(['applicant', 'organization.college', 'college', 'programs'])
            ->where('status', AdminApplication::STATUS_PENDING)
            ->latest()
            ->get();

        $reviewedApplications = AdminApplication::with(['applicant', 'organization.college', 'college', 'reviewer'])
            ->whereIn('status', [AdminApplication::STATUS_APPROVED, AdminApplication::STATUS_REJECTED])
            ->latest('reviewed_at')
            ->take(20)
            ->get();

        $assignments = AdminAssignment::with(['user', 'organization.college', 'college', 'approver'])
            ->latest()
            ->paginate(15);

        $organizations = Organization::with('college')->where('status', 'active')->orderBy('name')->get();
        $colleges = College::orderBy('name')->get();
        $adminRoles = AdminAssignmentService::ADMIN_ROLES;

        return view('dashboard.admin-users.index', compact(
            'pendingApplications',
            'reviewedApplications',
            'assignments',
            'organizations',
            'colleges',
            'adminRoles'
        ));
    }

    public function approve(AdminApplication $application, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $request->validate(['review_remarks' => 'nullable|string|max:1000']);

        $service->approveApplication($application, $request->user(), $request->input('review_remarks'));

        return redirect()->route('dashboard.admin-users')
            ->with('success', 'Admin application approved and assignment activated.');
    }

    public function reject(AdminApplication $application, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $request->validate(['review_remarks' => 'required|string|max:1000']);

        $service->rejectApplication($application, $request->user(), $request->input('review_remarks'));

        return redirect()->route('dashboard.admin-users')
            ->with('success', 'Admin application rejected with remarks.');
    }

    public function store(Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required_without:student_id_number', 'nullable', 'email', 'max:255'],
            'student_id_number' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(AdminAssignmentService::ADMIN_ROLES)],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'is_primary_admin' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $role = $request->input('role');
            $organization = $request->filled('organization_id')
                ? Organization::find($request->input('organization_id'))
                : null;

            if ($role === 'Society Admin' && !$organization) {
                $validator->errors()->add('organization_id', 'Select a Society organization for Society Admin assignments.');
                return;
            }

            if ($role === 'Society Admin' && $organization?->type !== 'society') {
                $validator->errors()->add('organization_id', 'Society Admin assignments must use a Society organization.');
            }

            if ($role === 'LSG Admin' && !$request->filled('college_id')) {
                $validator->errors()->add('college_id', 'Select a College for LSG Admin assignments.');
            }

            if ($role === 'LSG Admin' && $organization && $organization->type !== 'lsg') {
                $validator->errors()->add('organization_id', 'LSG Admin assignments must use an LSG organization.');
            }

            if ($role === 'USG Admin' && $organization && $organization->type !== 'usg') {
                $validator->errors()->add('organization_id', 'USG Admin assignments must use the USG organization.');
            }

            if ($role === 'ARO Admin' && $organization && $organization->type !== 'aro') {
                $validator->errors()->add('organization_id', 'ARO Admin assignments must use the ARO organization.');
            }

            if ($request->filled('college_id') && $organization?->college_id && $organization->college_id !== $request->input('college_id')) {
                $validator->errors()->add('organization_id', 'Select an organization inside the selected College.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $targetUser = $this->resolveOrCreateUser($request);
        [$organization, $college] = $this->resolveAssignmentScope($request);

        $service->createAssignment(
            user: $targetUser,
            roleName: $request->input('role'),
            organization: $organization,
            college: $college,
            academicYear: $request->input('academic_year') ?: $this->defaultAcademicYear(),
            termStart: $request->input('term_start'),
            termEnd: $request->input('term_end'),
            positionTitle: $request->input('position_title'),
            approvedBy: $request->user(),
            isPrimaryAdmin: $request->boolean('is_primary_admin', true)
        );

        return redirect()->route('dashboard.admin-users')
            ->with('success', 'Admin assignment activated through the assignment service.');
    }

    public function revoke(AdminAssignment $assignment, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $request->validate(['reason' => 'nullable|string|max:1000']);

        $service->revokeAssignment($assignment, $request->user(), $request->input('reason'));

        return redirect()->route('dashboard.admin-users')
            ->with('success', 'Admin assignment revoked.');
    }

    public function expire(AdminAssignment $assignment, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $service->expireAssignment($assignment, $request->user());

        return redirect()->route('dashboard.admin-users')
            ->with('success', 'Admin assignment expired.');
    }

    private function authorizeOsa(Request $request): void
    {
        if (!$request->user()?->hasRole('Super Admin (OSA)')) {
            abort(403);
        }
    }

    private function resolveOrCreateUser(Request $request): User
    {
        $user = null;

        if ($request->filled('student_id_number')) {
            $user = User::where('student_id_number', $request->input('student_id_number'))->first();
        }

        if (!$user && $request->filled('email')) {
            $user = User::where('email', $request->input('email'))->first();
        }

        if ($user) {
            return $user;
        }

        $email = $request->input('email');
        $user = User::create([
            'name' => $request->input('name') ?: str($email)->before('@')->replace('.', ' ')->title(),
            'email' => $email,
            'password' => $request->filled('password') ? Hash::make($request->input('password')) : null,
        ]);

        $user->assignRole('Student');

        return $user;
    }

    private function resolveAssignmentScope(Request $request): array
    {
        $role = $request->input('role');
        $organization = $request->filled('organization_id') ? Organization::find($request->input('organization_id')) : null;
        $college = $request->filled('college_id') ? College::find($request->input('college_id')) : null;

        if (!$organization && $role === 'USG Admin') {
            $organization = Organization::where('type', 'usg')->first();
        }

        if (!$organization && $role === 'ARO Admin') {
            $organization = Organization::where('type', 'aro')->first();
        }

        if (!$organization && $role === 'LSG Admin' && $college) {
            $organization = Organization::where('type', 'lsg')->where('college_id', $college->id)->first();
        }

        if (!$college && $organization?->college_id) {
            $college = $organization->college;
        }

        return [$organization, $college];
    }

    private function defaultAcademicYear(): string
    {
        $year = now()->year;

        return $year . '-' . ($year + 1);
    }
}
