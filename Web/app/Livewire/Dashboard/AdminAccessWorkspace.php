<?php

namespace App\Livewire\Dashboard;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\OrganizationManagementService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AdminAccessWorkspace extends Component
{
    use WithPagination;

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
    ];

    #[Url(as: 'tab', except: 'organizations')]
    public string $activeTab = 'organizations';

    #[Url(as: 'org_search', except: '')]
    public string $orgSearch = '';

    #[Url(as: 'org_type', except: '')]
    public string $orgType = '';

    #[Url(as: 'org_status', except: '')]
    public string $orgStatus = '';

    #[Url(as: 'assign_search', except: '')]
    public string $assignSearch = '';

    #[Url(as: 'assign_role', except: '')]
    public string $assignRole = '';

    #[Url(as: 'assign_status', except: '')]
    public string $assignStatus = '';

    #[Url(as: 'assign_term', except: '')]
    public string $assignTerm = '';

    #[Url(as: 'audit_search', except: '')]
    public string $auditSearch = '';

    #[Url(as: 'audit_action', except: '')]
    public string $auditAction = '';

    #[Url(as: 'audit_from', except: '')]
    public string $auditFrom = '';

    #[Url(as: 'audit_to', except: '')]
    public string $auditTo = '';

    #[Locked]
    public array $collegePrograms = [];

    public array $revokeReasons = [];

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(string $initialTab = 'organizations', array $collegePrograms = []): void
    {
        $this->authorizeOsa();

        if (in_array($initialTab, ['organizations', 'assignments', 'audit'], true)) {
            $this->activeTab = $initialTab;
        }

        $this->collegePrograms = $collegePrograms;
        $this->orgSearch = trim((string) request('org_search', $this->orgSearch));
        $this->orgType = (string) request('org_type', $this->orgType);
        $this->orgStatus = (string) request('org_status', $this->orgStatus);
        $this->assignSearch = trim((string) request('assign_search', $this->assignSearch));
        $this->assignRole = (string) request('assign_role', $this->assignRole);
        $this->assignStatus = (string) request('assign_status', $this->assignStatus);
        $this->assignTerm = (string) request('assign_term', $this->assignTerm);
        $this->auditSearch = trim((string) request('audit_search', $this->auditSearch));
        $this->auditAction = (string) request('audit_action', $this->auditAction);
        $this->auditFrom = (string) request('audit_from', $this->auditFrom);
        $this->auditTo = (string) request('audit_to', $this->auditTo);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['organizations', 'assignments', 'audit'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function updated(string $property): void
    {
        $this->notice = null;
        $this->errorNotice = null;

        if (str_starts_with($property, 'org')) {
            $this->resetPage('orgsPage');
        }

        if (str_starts_with($property, 'assign')) {
            $this->resetPage('assignmentsPage');
        }

        if (str_starts_with($property, 'audit')) {
            $this->resetPage('auditPage');
        }
    }

    public function clearOrgFilters(): void
    {
        $this->orgSearch = '';
        $this->orgType = '';
        $this->orgStatus = '';
        $this->resetPage('orgsPage');
    }

    public function clearAssignmentFilters(): void
    {
        $this->assignSearch = '';
        $this->assignRole = '';
        $this->assignStatus = '';
        $this->assignTerm = '';
        $this->resetPage('assignmentsPage');
    }

    public function clearAuditFilters(): void
    {
        $this->auditSearch = '';
        $this->auditAction = '';
        $this->auditFrom = '';
        $this->auditTo = '';
        $this->resetPage('auditPage');
    }

    public function expireAssignment(string $assignmentId): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $assignment = AdminAssignment::with('user')->findOrFail($assignmentId);

        if ($assignment->status !== AdminAssignment::STATUS_ACTIVE) {
            $this->errorNotice = 'Only active admin assignments can be expired.';

            return;
        }

        app(AdminAssignmentService::class)->expireAssignment($assignment, auth()->user());
        $this->notice = 'Admin assignment expired.';
    }

    public function revokeAssignment(string $assignmentId): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $assignment = AdminAssignment::with('user')->findOrFail($assignmentId);

        if ($assignment->status !== AdminAssignment::STATUS_ACTIVE) {
            $this->errorNotice = 'Only active admin assignments can be revoked.';

            return;
        }

        $reason = trim((string) ($this->revokeReasons[$assignmentId] ?? 'Revoked by OSA'));

        if (mb_strlen($reason) > 1000) {
            $this->addError("revokeReasons.{$assignmentId}", 'The reason must not exceed 1000 characters.');

            return;
        }

        app(AdminAssignmentService::class)->revokeAssignment(
            $assignment,
            auth()->user(),
            $reason !== '' ? $reason : null
        );

        unset($this->revokeReasons[$assignmentId]);
        $this->notice = 'Admin assignment revoked.';
        $this->dispatch('close-admin-revoke-modal', id: $assignmentId);
    }

    public function render()
    {
        $this->authorizeOsa();

        $assignments = $this->assignmentQuery()
            ->paginate(15, ['*'], 'assignmentsPage');

        $organizations = $this->organizationQuery()
            ->paginate(8, ['*'], 'orgsPage');

        $auditLogs = $this->auditQuery()
            ->paginate(12, ['*'], 'auditPage');

        [$auditUsers, $auditOrganizations, $auditColleges] = $this->resolveAuditContext($auditLogs);

        return view('livewire.dashboard.admin-access-workspace', [
            'assignments' => $assignments,
            'organizations' => $organizations,
            'auditLogs' => $auditLogs,
            'auditUsers' => $auditUsers,
            'auditOrganizations' => $auditOrganizations,
            'auditColleges' => $auditColleges,
            'adminRoles' => AdminAssignmentService::ADMIN_ROLES,
            'auditActions' => self::AUDIT_ACTIONS,
            'auditActionLabels' => self::AUDIT_ACTION_LABELS,
        ]);
    }

    private function assignmentQuery()
    {
        $currentAy = $this->defaultAcademicYear();
        $assignSearch = trim($this->assignSearch);

        return AdminAssignment::with(['user', 'organization.college', 'college', 'approver'])
            ->when($this->assignRole !== '', function ($query) {
                $query->where('role_name', $this->assignRole);
            })
            ->when(in_array($this->assignStatus, ['active', 'expired', 'revoked'], true), function ($query) {
                $query->where('status', $this->assignStatus);
            })
            ->when($this->assignTerm === 'current', function ($query) use ($currentAy) {
                $query->where('academic_year', $currentAy);
            })
            ->when($this->assignTerm === 'previous', function ($query) use ($currentAy) {
                $query->where('academic_year', '!=', $currentAy);
            })
            ->when($assignSearch !== '', function ($query) use ($assignSearch) {
                $needle = '%'.mb_strtolower($assignSearch).'%';
                $query->where(function ($inner) use ($needle) {
                    $inner->whereHas('user', function ($userQuery) use ($needle) {
                        $userQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
                    })
                        ->orWhereHas('organization', function ($organizationQuery) use ($needle) {
                            $organizationQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(acronym) LIKE ?', [$needle]);
                        })
                        ->orWhereHas('college', function ($collegeQuery) use ($needle) {
                            $collegeQuery->whereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(code) LIKE ?', [$needle]);
                        })
                        ->orWhereRaw('LOWER(role_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(position_title) LIKE ?', [$needle]);
                });
            })
            ->latest();
    }

    private function organizationQuery()
    {
        $orgSearch = trim($this->orgSearch);

        return Organization::with(['college.programs', 'programs'])
            ->whereIn('type', OrganizationManagementService::ORGANIZATION_TYPES)
            ->when(in_array($this->orgType, OrganizationManagementService::ORGANIZATION_TYPES, true), function ($query) {
                $query->where('type', $this->orgType);
            })
            ->when(in_array($this->orgStatus, ['active', 'inactive'], true), function ($query) {
                $query->where('status', $this->orgStatus);
            })
            ->when($orgSearch !== '', function ($query) use ($orgSearch) {
                $needle = '%'.mb_strtolower($orgSearch).'%';
                $query->where(function ($inner) use ($needle) {
                    $inner->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(acronym) LIKE ?', [$needle]);
                });
            })
            ->orderBy('name');
    }

    private function auditQuery()
    {
        $auditSearch = trim($this->auditSearch);

        return AuditLog::with('admin')
            ->whereIn('action', self::AUDIT_ACTIONS)
            ->when(in_array($this->auditAction, self::AUDIT_ACTIONS, true), function ($query) {
                $query->where('action', $this->auditAction);
            })
            ->when($this->isDateString($this->auditFrom), function ($query) {
                $query->whereDate('created_at', '>=', $this->auditFrom);
            })
            ->when($this->isDateString($this->auditTo), function ($query) {
                $query->whereDate('created_at', '<=', $this->auditTo);
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
            ->latest();
    }

    private function resolveAuditContext($auditLogs): array
    {
        $userIds = [];
        $organizationIds = [];
        $collegeIds = [];

        foreach ($auditLogs as $log) {
            $details = $log->details ?? [];

            if (! empty($details['user_id'])) {
                $userIds[] = $details['user_id'];
            }

            if (! empty($details['organization_id'])) {
                $organizationIds[] = $details['organization_id'];
            }

            if (! empty($details['college_id'])) {
                $collegeIds[] = $details['college_id'];
            }
        }

        return [
            User::whereIn('id', array_unique($userIds))->get()->keyBy('id'),
            Organization::whereIn('id', array_unique($organizationIds))->get()->keyBy('id'),
            College::whereIn('id', array_unique($collegeIds))->get()->keyBy('id'),
        ];
    }

    private function authorizeOsa(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin (OSA)'), 403);
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
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
}
