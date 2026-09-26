<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOsaAuthorityOverrideRequest;
use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Organization;
use App\Models\OsaAuthorityOverride;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\OrganizationManagementService;
use App\Services\OsaAuthorityOverrideService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAccessController extends Controller
{
    private const AUDIT_ACTIONS = [
        'create-admin-assignment',
        'revoke-admin-assignment',
        'expire-admin-assignment',
        'approve-admin-application',
        'reject-admin-application',
        'create-department',
        'update-department',
        'create-school-year',
        'activate-school-year',
        'open-school-year-transition',
        'close-school-year-transition',
        'create-organization',
        'update-organization',
        'update-organization-logo',
        'delete-organization-logo',
        'execute-osa-authority-override',
        'create-proposal-process-template',
        'update-proposal-process-template-draft',
        'publish-proposal-process-template-version',
        'retire-proposal-process-template-version',
        'create-proposal-process-template-draft-version',
    ];

    private const AUDIT_ACTION_LABELS = [
        'create-admin-assignment' => 'Assignment activated',
        'revoke-admin-assignment' => 'Assignment revoked',
        'expire-admin-assignment' => 'Assignment expired',
        'approve-admin-application' => 'Admin Application Approved',
        'reject-admin-application' => 'Admin Application Rejected',
        'create-department' => 'Department Created',
        'update-department' => 'Department Updated',
        'create-school-year' => 'School Year Created',
        'activate-school-year' => 'School Year Activated',
        'open-school-year-transition' => 'School Year Transition Opened',
        'close-school-year-transition' => 'School Year Transition Closed',
        'create-organization' => 'Organization Created',
        'update-organization' => 'Organization Updated',
        'update-organization-logo' => 'Organization Logo Updated',
        'delete-organization-logo' => 'Organization Logo Deleted',
        'execute-osa-authority-override' => 'OSA Authority Override',
        'create-proposal-process-template' => 'Workflow Template Created',
        'update-proposal-process-template-draft' => 'Workflow Template Draft Updated',
        'publish-proposal-process-template-version' => 'Workflow Template Published',
        'retire-proposal-process-template-version' => 'Workflow Template Retired',
        'create-proposal-process-template-draft-version' => 'Workflow Template Draft Version Created',
    ];

    public function index(Request $request)
    {
        $this->authorizeOsa($request);

        $orgSearch = trim((string) $request->input('org_search', ''));
        $orgType = $request->input('org_type');
        $orgStatus = $request->input('org_status');

        $assignSearch = trim((string) $request->input('assign_search', ''));
        $assignRole = $request->input('assign_role');
        $assignStatus = $request->input('assign_status');
        $assignTerm = $request->input('assign_term');
        $currentAy = $this->defaultAcademicYear();

        $auditSearch = trim((string) $request->input('audit_search', ''));
        $auditAction = $request->input('audit_action');
        $auditFrom = $request->input('audit_from');
        $auditTo = $request->input('audit_to');

        $activeTab = $request->input('tab');
        if (! in_array($activeTab, ['organizations', 'assignments', 'overrides', 'audit'], true)) {
            if ($request->has('orgs_page') || $request->has('org_search') || $request->has('org_type') || $request->has('org_status')) {
                $activeTab = 'organizations';
            } elseif ($request->has('page') || $request->has('assign_search') || $request->has('assign_role') || $request->has('assign_status') || $request->has('assign_term')) {
                $activeTab = 'assignments';
            } elseif ($request->has('overrides_page')) {
                $activeTab = 'overrides';
            } elseif ($request->has('audit_page') || $request->has('audit_search') || $request->has('audit_action') || $request->has('audit_from') || $request->has('audit_to')) {
                $activeTab = 'audit';
            } else {
                $activeTab = 'organizations';
            }
        }

        $assignments = AdminAssignment::with(['user', 'organization.college', 'college', 'approver'])
            ->when($assignRole !== null && $assignRole !== '', function ($query) use ($assignRole) {
                $query->where('role_name', $assignRole);
            })
            ->when(in_array($assignStatus, ['active', 'expired', 'revoked'], true), function ($query) use ($assignStatus) {
                $query->where('status', $assignStatus);
            })
            ->when($assignTerm === 'current', function ($query) use ($currentAy) {
                $query->where('academic_year', $currentAy);
            })
            ->when($assignTerm === 'previous', function ($query) use ($currentAy) {
                $query->where('academic_year', '!=', $currentAy);
            })
            ->when($assignSearch !== '', function ($query) use ($assignSearch) {
                $needle = '%'.mb_strtolower($assignSearch).'%';
                $query->where(function ($inner) use ($needle) {
                    $inner->whereHas('user', function ($uQuery) use ($needle) {
                        $uQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
                    })
                        ->orWhereHas('organization', function ($oQuery) use ($needle) {
                            $oQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(acronym) LIKE ?', [$needle]);
                        })
                        ->orWhereHas('college', function ($cQuery) use ($needle) {
                            $cQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(code) LIKE ?', [$needle]);
                        })
                        ->orWhereRaw('LOWER(role_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(position_title) LIKE ?', [$needle]);
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $organizations = Organization::with(['college', 'department', 'programs'])
            ->whereIn('type', OrganizationManagementService::ORGANIZATION_TYPES)
            ->when(in_array($orgType, OrganizationManagementService::ORGANIZATION_TYPES, true), function ($query) use ($orgType) {
                $query->where('type', $orgType);
            })
            ->when(in_array($orgStatus, ['active', 'inactive'], true), function ($query) use ($orgStatus) {
                $query->where('status', $orgStatus);
            })
            ->when($orgSearch !== '', function ($query) use ($orgSearch) {
                $needle = '%'.mb_strtolower($orgSearch).'%';

                $query->where(function ($inner) use ($needle) {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(acronym) LIKE ?', [$needle]);
                });
            })
            ->orderBy('name')
            ->paginate(8, ['*'], 'orgs_page')
            ->withQueryString();

        $activeOrganizations = Organization::with(['college', 'department'])
            ->whereIn('type', OrganizationManagementService::ORGANIZATION_TYPES)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $auditLogs = AuditLog::with('admin')
            ->whereIn('action', self::AUDIT_ACTIONS)
            ->when(in_array($auditAction, self::AUDIT_ACTIONS, true), function ($query) use ($auditAction) {
                $query->where('action', $auditAction);
            })
            ->when($this->isDateString($auditFrom), function ($query) use ($auditFrom) {
                $query->whereDate('created_at', '>=', $auditFrom);
            })
            ->when($this->isDateString($auditTo), function ($query) use ($auditTo) {
                $query->whereDate('created_at', '<=', $auditTo);
            })
            ->when($auditSearch !== '', function ($query) use ($auditSearch) {
                $needle = '%'.mb_strtolower($auditSearch).'%';
                $query->where(function ($inner) use ($needle) {
                    $inner->whereRaw('LOWER(action) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(CAST(details AS TEXT)) LIKE ?', [$needle])
                        ->orWhereHas('admin', function ($adminQuery) use ($needle) {
                            $adminQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
                        });
                });
            })
            ->latest()
            ->paginate(12, ['*'], 'audit_page')
            ->withQueryString();

        [$auditUsers, $auditOrganizations, $auditColleges] = $this->resolveAuditContext($auditLogs);

        $colleges = College::with(['departments', 'programs'])->orderBy('name')->get();
        $schoolYears = SchoolYear::query()->orderByDesc('starts_on')->get();
        $adminRoles = AdminAssignmentService::ADMIN_ROLES;
        $assignableRoles = AdminAssignmentService::OSA_ASSIGNABLE_ROLES;
        $auditActions = self::AUDIT_ACTIONS;
        $auditActionLabels = $this->auditActionLabels();
        $stats = [
            'active_assignments' => AdminAssignment::where('status', AdminAssignment::STATUS_ACTIVE)->count(),
            'total_assignments' => AdminAssignment::count(),
            'active_organizations' => Organization::whereIn('type', OrganizationManagementService::ORGANIZATION_TYPES)->where('status', 'active')->count(),
            'total_organizations' => Organization::whereIn('type', OrganizationManagementService::ORGANIZATION_TYPES)->count(),
            'audit_logs' => AuditLog::whereIn('action', self::AUDIT_ACTIONS)->count(),
            'active_overrides' => OsaAuthorityOverride::where('status', OsaAuthorityOverride::STATUS_ACTIVE)->count(),
        ];

        return view('dashboard.admin-users.index', compact(
            'assignments',
            'organizations',
            'activeOrganizations',
            'colleges',
            'schoolYears',
            'adminRoles',
            'assignableRoles',
            'auditLogs',
            'auditActions',
            'auditActionLabels',
            'auditUsers',
            'auditOrganizations',
            'auditColleges',
            'stats',
            'activeTab'
        ));
    }

    public function searchUsers(Request $request)
    {
        $this->authorizeOsa($request);

        $query = trim((string) $request->input('q', ''));
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $like = '%'.$query.'%';

        $users = User::query()
            ->where(function ($builder) use ($like) {
                $builder->where('email', 'like', $like)
                    ->orWhere('student_id_number', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'student_id_number']);

        return response()->json($users);
    }

    public function store(Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);
        $this->normalizeAssignmentPerson($request);

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'person_query' => ['nullable', 'string', 'max:255'],
            'email' => ['required_without:student_id_number', 'nullable', 'email', 'max:255'],
            'student_id_number' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in(AdminAssignmentService::OSA_ASSIGNABLE_ROLES)],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'is_primary_admin' => ['nullable', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $this->validateAssignmentScope($validator, $request);
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

        return redirect()->route('dashboard.admin-users', ['tab' => 'assignments'])
            ->with('success', 'Admin assignment activated.');
    }

    public function storeOrganization(Request $request, OrganizationManagementService $service)
    {
        $this->authorizeOsa($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'acronym' => ['required', 'string', 'max:20'],
            'type' => ['required', Rule::in(OrganizationManagementService::ORGANIZATION_TYPES)],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['uuid', 'exists:programs,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator, 'organization')->withInput();
        }

        try {
            $service->create($validator->validated(), $request->user());
        } catch (ValidationException $exception) {
            return redirect()->back()
                ->withErrors($exception->errors(), 'organization')
                ->withInput();
        }

        return redirect()->route('dashboard.admin-users', ['tab' => 'organizations'])
            ->with('success', 'Organization created.');
    }

    public function updateOrganization(Organization $organization, Request $request, OrganizationManagementService $service)
    {
        $this->authorizeOsa($request);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'acronym' => ['required', 'string', 'max:20'],
            'college_id' => ['nullable', 'uuid', 'exists:colleges,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['uuid', 'exists:programs,id'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator, 'organization')
                ->withInput()
                ->with('org_edit_error_id', $organization->id);
        }

        try {
            $service->update($organization, $validator->validated(), $request->user());
        } catch (ValidationException $exception) {
            return redirect()->back()
                ->withErrors($exception->errors(), 'organization')
                ->withInput()
                ->with('org_edit_error_id', $organization->id);
        }

        return redirect()->route('dashboard.admin-users', ['tab' => 'organizations'])
            ->with('success', 'Organization updated.');
    }

    public function revoke(AdminAssignment $assignment, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $request->validate(['reason' => 'nullable|string|max:1000']);

        $service->revokeAssignment($assignment, $request->user(), $request->input('reason'));

        return redirect()->route('dashboard.admin-users', ['tab' => 'assignments'])
            ->with('success', 'Admin assignment revoked.');
    }

    public function expire(AdminAssignment $assignment, Request $request, AdminAssignmentService $service)
    {
        $this->authorizeOsa($request);

        $service->expireAssignment($assignment, $request->user());

        return redirect()->route('dashboard.admin-users', ['tab' => 'assignments'])
            ->with('success', 'Admin assignment expired.');
    }

    public function storeAuthorityOverride(StoreOsaAuthorityOverrideRequest $request, OsaAuthorityOverrideService $service): RedirectResponse
    {
        $override = $service->execute($request->validated(), $request->user());

        return redirect()->route('dashboard.admin-users', ['tab' => 'overrides'])
            ->with('success', str($override->action)->headline().' authority override recorded.');
    }

    private function authorizeOsa(Request $request): void
    {
        if (! $request->user()?->hasRole('Super Admin (OSA)')) {
            abort(403);
        }
    }

    private function validateAssignmentScope($validator, Request $request): void
    {
        $role = $request->input('role');
        $organization = $request->filled('organization_id')
            ? Organization::find($request->input('organization_id'))
            : null;

        if ($organization && ! in_array($organization->type, OrganizationManagementService::ORGANIZATION_TYPES, true)) {
            $validator->errors()->add('organization_id', 'Select an active recognized organization.');
        }

        if ($role === 'Society Admin' && (! $organization || ! in_array($organization->type, ['society', 'non_academic_org'], true))) {
            $validator->errors()->add('organization_id', 'Select a society or recognized non-academic organization for Society Admin assignments.');
        }

        if ($role === 'LSG Admin') {
            if (! $request->filled('college_id')) {
                $validator->errors()->add('college_id', 'Select a College for LSG Admin assignments.');
            }

            if ($organization && $organization->type !== 'lsg') {
                $validator->errors()->add('organization_id', 'LSG Admin assignments must use an LSG organization.');
            }
        }

        if ($role === 'USG Admin') {
            if ($organization && $organization->type !== 'usg') {
                $validator->errors()->add('organization_id', 'USG Admin assignments must use the USG organization.');
            }

            if ($request->filled('college_id')) {
                $validator->errors()->add('college_id', 'USG Admin assignments are university-wide.');
            }
        }

        if ($role === 'Academic Unit Admin') {
            if (! $organization || $organization->type !== 'academic_unit') {
                $validator->errors()->add('organization_id', 'Select an academic unit organization for Academic Unit Admin assignments.');
            }
        }

        if ($role === 'Office Event Admin') {
            if (! $organization || $organization->type !== 'administrative_office') {
                $validator->errors()->add('organization_id', 'Select an administrative office for Office Event Admin assignments.');
            }

            if ($request->filled('college_id')) {
                $validator->errors()->add('college_id', 'Office Event Admin assignments are office-scoped, not college-scoped.');
            }
        }

        if ($request->filled('college_id') && $organization?->college_id && $organization->college_id !== $request->input('college_id')) {
            $validator->errors()->add('organization_id', 'Select an organization inside the selected College.');
        }
    }

    private function resolveOrCreateUser(Request $request): User
    {
        $user = null;

        if ($request->filled('student_id_number')) {
            $user = User::where('student_id_number', $request->input('student_id_number'))->first();
        }

        if (! $user && $request->filled('email')) {
            $user = User::where('email', $request->input('email'))->first();
        }

        if ($user) {
            return $user;
        }

        $email = $request->input('email');
        if (! $email) {
            throw ValidationException::withMessages([
                'person_query' => 'Select an existing account result, or create a new staff account with a USM email address.',
            ]);
        }

        $user = User::create([
            'name' => $request->input('name') ?: str($email)->before('@')->replace('.', ' ')->title(),
            'email' => $email,
            'password' => $request->filled('password') ? Hash::make($request->input('password')) : null,
            'user_type' => str_starts_with(strtolower($email), 's.') ? 'student' : 'faculty_or_staff_pending',
        ]);

        if ($user->user_type === 'student' && ! $user->hasRole('Student')) {
            $user->assignRole('Student');
        }

        return $user;
    }

    private function normalizeAssignmentPerson(Request $request): void
    {
        if ($request->filled('email') || $request->filled('student_id_number')) {
            return;
        }

        $query = trim((string) $request->input('person_query', ''));

        if ($query === '') {
            return;
        }

        if (str_contains($query, '@')) {
            $request->merge(['email' => $query]);

            return;
        }

        $request->merge(['student_id_number' => $query]);
    }

    private function resolveAssignmentScope(Request $request): array
    {
        $role = $request->input('role');
        $organization = $request->filled('organization_id') ? Organization::find($request->input('organization_id')) : null;
        $college = $request->filled('college_id') ? College::find($request->input('college_id')) : null;

        if (! $organization && $role === 'USG Admin') {
            $organization = Organization::where('type', 'usg')->where('status', 'active')->first();
        }

        if (! $organization && $role === 'LSG Admin' && $college) {
            $organization = Organization::where('type', 'lsg')->where('college_id', $college->id)->first();
        }

        if (! $college && $organization?->college_id) {
            $college = $organization->college;
        }

        return [$organization, $college];
    }

    private function defaultAcademicYear(): string
    {
        $year = now()->year;

        return $year.'-'.($year + 1);
    }

    private function isDateString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function resolveAuditContext($auditLogs): array
    {
        $userIds = [];
        $organizationIds = [];
        $collegeIds = [];

        foreach ($auditLogs as $log) {
            $details = $log->details ?? [];

            foreach (['user_id'] as $key) {
                if (! empty($details[$key])) {
                    $userIds[] = $details[$key];
                }
            }

            foreach (['organization_id'] as $key) {
                if (! empty($details[$key])) {
                    $organizationIds[] = $details[$key];
                }
            }

            foreach (['college_id'] as $key) {
                if (! empty($details[$key])) {
                    $collegeIds[] = $details[$key];
                }
            }
        }

        return [
            User::whereIn('id', array_unique($userIds))->get()->keyBy('id'),
            Organization::whereIn('id', array_unique($organizationIds))->get()->keyBy('id'),
            College::whereIn('id', array_unique($collegeIds))->get()->keyBy('id'),
        ];
    }

    private function auditActionLabels(): array
    {
        return self::AUDIT_ACTION_LABELS;
    }
}
