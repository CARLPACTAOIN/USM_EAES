<?php

namespace App\Livewire\Dashboard;

use App\Http\Controllers\Web\AnalyticsOverviewController;
use App\Services\AdminScopeService;
use Livewire\Attributes\Url;
use Livewire\Component;

class AnalyticsOverviewFilters extends Component
{
    #[Url(as: 'school_year', except: '')]
    public string $schoolYear = '';

    #[Url(except: '')]
    public string $semester = '';

    #[Url(as: 'college_id', except: '')]
    public string $collegeId = '';

    #[Url(as: 'department_id', except: '')]
    public string $departmentId = '';

    #[Url(as: 'organization_id', except: '')]
    public string $organizationId = '';

    #[Url(as: 'program_id', except: '')]
    public string $programId = '';

    #[Url(as: 'year_level', except: '')]
    public string $yearLevel = '';

    #[Url(as: 'event_type', except: '')]
    public string $eventType = '';

    public function mount(
        ?string $selectedSchoolYear = '',
        ?string $selectedSemester = '',
        ?string $selectedCollegeId = '',
        ?string $selectedDepartmentId = '',
        ?string $selectedOrgId = '',
        ?string $selectedProgramId = '',
        ?string $selectedYearLevel = '',
        ?string $selectedEventType = '',
    ): void {
        $this->schoolYear = (string) $selectedSchoolYear;
        $this->semester = (string) $selectedSemester;
        $this->collegeId = (string) $selectedCollegeId;
        $this->departmentId = (string) $selectedDepartmentId;
        $this->organizationId = (string) $selectedOrgId;
        $this->programId = (string) $selectedProgramId;
        $this->yearLevel = (string) $selectedYearLevel;
        $this->eventType = (string) $selectedEventType;

        $this->normalizeLockedScope();
    }

    public function updatedSchoolYear(): void
    {
        $this->normalizeLockedScope();
    }

    public function updatedCollegeId(): void
    {
        $this->departmentId = '';
        $this->programId = '';
        $this->organizationId = '';
        $this->normalizeLockedScope();
    }

    public function updatedDepartmentId(): void
    {
        $this->programId = '';
        $this->organizationId = '';
        $this->normalizeLockedScope();
    }

    public function updatedProgramId(): void
    {
        $this->organizationId = '';
        $this->normalizeLockedScope();
    }

    public function updatedOrganizationId(): void
    {
        $this->normalizeLockedScope();
    }

    public function clearFilters(): mixed
    {
        $this->schoolYear = '';
        $this->semester = '';
        $this->collegeId = '';
        $this->departmentId = '';
        $this->organizationId = '';
        $this->programId = '';
        $this->yearLevel = '';
        $this->eventType = '';

        $this->normalizeLockedScope();

        return null;
    }

    public function applyFilters(): mixed
    {
        $this->normalizeLockedScope();
        $this->schoolYear = trim($this->schoolYear);
        $this->semester = trim($this->semester);
        $this->collegeId = trim($this->collegeId);
        $this->departmentId = trim($this->departmentId);
        $this->organizationId = trim($this->organizationId);
        $this->programId = trim($this->programId);
        $this->yearLevel = trim($this->yearLevel);
        $this->eventType = trim($this->eventType);

        return null;
    }

    public function render()
    {
        $user = auth()->user();
        $this->normalizeLockedScope();
        $analytics = app(AnalyticsOverviewController::class)->dataFor($user, $this->filters());
        $scope = $analytics['scope'];
        $filterColleges = $analytics['filterColleges'];
        $filterDepartments = $analytics['filterDepartments'];
        $filterOrganizations = $analytics['filterOrganizations'];
        $filterPrograms = $analytics['filterPrograms'];

        return view('livewire.dashboard.analytics-overview-filters', $analytics + [
            'activeFilterCount' => $this->activeFilterCount(),
            'canSelectCollege' => $scope->isSuperAdmin() || $scope->isUsgAdmin(),
            'canSelectDepartment' => $scope->isSuperAdmin() || $scope->isUsgAdmin() || $scope->isLsgAdmin(),
            'canSelectProgram' => $scope->isSuperAdmin() || $scope->isUsgAdmin() || $scope->isLsgAdmin() || $scope->isAcademicUnitAdmin() || ($scope->organization && $filterPrograms->count() > 1),
            'canSelectOrganization' => $scope->isSuperAdmin() || $scope->isUsgAdmin() || $scope->isLsgAdmin() || $scope->isAcademicUnitAdmin(),
            'selectedCollege' => $filterColleges->firstWhere('id', $this->collegeId),
            'selectedDepartment' => $filterDepartments->firstWhere('id', $this->departmentId),
            'selectedOrganization' => $filterOrganizations->firstWhere('id', $this->organizationId),
            'selectedProgram' => $filterPrograms->firstWhere('id', $this->programId),
        ]);
    }

    private function normalizeLockedScope(): void
    {
        $scope = $this->scope();

        if ($scope->isLsgAdmin()) {
            $this->collegeId = (string) $scope->collegeId;

            return;
        }

        if ($scope->isAcademicUnitAdmin()) {
            $this->collegeId = (string) $scope->collegeId;
            $this->departmentId = (string) $scope->departmentId;

            return;
        }

        if (! $scope->isSuperAdmin() && ! $scope->isUsgAdmin()) {
            $this->organizationId = (string) $scope->organizationId;
            $this->collegeId = (string) $scope->collegeId;
            if ($scope->departmentId) {
                $this->departmentId = (string) $scope->departmentId;
            }
        }
    }

    private function scope()
    {
        $scope = app(AdminScopeService::class)->forUser(auth()->user());

        abort_unless($scope, 403, 'Access denied. No active admin assignment found.');

        return $scope;
    }

    private function filters(): array
    {
        return [
            'school_year' => filled($this->schoolYear) ? $this->schoolYear : null,
            'semester' => filled($this->semester) ? $this->semester : null,
            'college_id' => filled($this->collegeId) ? $this->collegeId : null,
            'department_id' => filled($this->departmentId) ? $this->departmentId : null,
            'organization_id' => filled($this->organizationId) ? $this->organizationId : null,
            'program_id' => filled($this->programId) ? $this->programId : null,
            'year_level' => filled($this->yearLevel) ? $this->yearLevel : null,
            'event_type' => filled($this->eventType) ? $this->eventType : null,
        ];
    }

    private function activeFilterCount(): int
    {
        return collect([
            $this->schoolYear,
            $this->semester,
            $this->collegeId,
            $this->departmentId,
            $this->organizationId,
            $this->programId,
            $this->yearLevel,
            $this->eventType,
        ])->filter(fn ($value) => filled($value))->count();
    }
}
