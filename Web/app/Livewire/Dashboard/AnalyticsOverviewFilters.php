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
        ?string $selectedOrgId = '',
        ?string $selectedProgramId = '',
        ?string $selectedYearLevel = '',
        ?string $selectedEventType = '',
    ): void {
        $this->schoolYear = (string) $selectedSchoolYear;
        $this->semester = (string) $selectedSemester;
        $this->collegeId = (string) $selectedCollegeId;
        $this->organizationId = (string) $selectedOrgId;
        $this->programId = (string) $selectedProgramId;
        $this->yearLevel = (string) $selectedYearLevel;
        $this->eventType = (string) $selectedEventType;

        $this->normalizeLockedScope();
    }

    public function updatedCollegeId(): void
    {
        $this->organizationId = '';
        $this->programId = '';
        $this->normalizeLockedScope();
    }

    public function updatedOrganizationId(): void
    {
        $this->programId = '';
        $this->normalizeLockedScope();
    }

    public function clearFilters(): mixed
    {
        $this->schoolYear = '';
        $this->semester = '';
        $this->collegeId = '';
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
        $filterOrganizations = $analytics['filterOrganizations'];
        $filterPrograms = $analytics['filterPrograms'];

        return view('livewire.dashboard.analytics-overview-filters', $analytics + [
            'activeFilterCount' => $this->activeFilterCount(),
            'canSelectCollege' => $scope->isSuperAdmin(),
            'canSelectOrganization' => $scope->isSuperAdmin() || $scope->isLsgAdmin(),
            'selectedCollege' => $filterColleges->firstWhere('id', $this->collegeId),
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

        if (!$scope->isSuperAdmin()) {
            $this->organizationId = (string) $scope->organizationId;
            $this->collegeId = (string) $scope->collegeId;
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
            $this->organizationId,
            $this->programId,
            $this->yearLevel,
            $this->eventType,
        ])->filter(fn ($value) => filled($value))->count();
    }
}
