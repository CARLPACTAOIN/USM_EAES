<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\OrganizationAnalyticsService;
use App\Support\YearLevels;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsOverviewController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly OrganizationAnalyticsService $organizationAnalytics,
    ) {}

    public function index(Request $request): View
    {
        return view('dashboard.analytics.index', $this->dataFor($request->user(), $request->query()));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dataFor(User $user, array $filters = []): array
    {
        $selectedCollegeId = $this->filterValue($filters, 'college_id');
        $selectedOrgId = $this->filterValue($filters, 'organization_id');
        $selectedProgramId = $this->filterValue($filters, 'program_id');
        $selectedYearLevel = $this->filterValue($filters, 'year_level');
        $selectedSchoolYear = $this->filterValue($filters, 'school_year');
        $selectedSemester = $this->filterValue($filters, 'semester');
        $selectedEventType = $this->filterValue($filters, 'event_type');
        $scope = $this->adminScopes->forUser($user);

        if (! $scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isLsgAdmin()) {
            $selectedCollegeId = $scope->collegeId;
        } elseif (! $scope->isSuperAdmin()) {
            $selectedOrgId = $scope->organizationId;
            $selectedCollegeId = $scope->collegeId;
        }

        $filterColleges = College::query()
            ->when($selectedCollegeId, fn ($query, $collegeId) => $query->where('id', $collegeId))
            ->orderBy('name')
            ->get();
        $filterOrganizations = Organization::query()
            ->when($selectedCollegeId, fn ($query, $collegeId) => $query->where('college_id', $collegeId))
            ->when($selectedOrgId, fn ($query, $organizationId) => $query->where('id', $organizationId))
            ->orderBy('name')
            ->get();
        $filterPrograms = Program::query()
            ->when(
                $selectedOrgId,
                fn ($query, $organizationId) => $query->whereHas(
                    'organizations',
                    fn ($organizationQuery) => $organizationQuery->where('organization_id', $organizationId),
                ),
                fn ($query) => $query->when($selectedCollegeId, fn ($query, $collegeId) => $query->where('college_id', $collegeId)),
            )
            ->orderBy('name')
            ->get();
        $schoolYears = Event::query()
            ->where('status', Event::STATUS_COMPLETED)
            ->orderByDesc('start_date')
            ->pluck('start_date')
            ->map(function ($date): ?string {
                if (! $date) {
                    return null;
                }

                $year = (int) $date->format('Y');
                $month = (int) $date->format('m');

                return $month < 6 ? ($year - 1).'-'.$year : $year.'-'.($year + 1);
            })
            ->filter()
            ->unique()
            ->values();
        $organizations = Organization::query()
            ->with('college')
            ->when($selectedCollegeId, fn ($query, $collegeId) => $query->where('college_id', $collegeId))
            ->when($selectedOrgId, fn ($query, $organizationId) => $query->where('id', $organizationId))
            ->orderBy('name')
            ->get();

        $orgMetrics = [];
        $globalAttendanceSum = 0.0;
        $globalEvaluationSum = 0.0;
        $globalEvaluationCount = 0;

        foreach ($organizations as $organization) {
            $metrics = $this->organizationAnalytics->organizationMetrics(
                $organization,
                $selectedProgramId,
                $selectedSchoolYear,
                $selectedSemester,
                $selectedEventType,
                $selectedYearLevel,
            );
            $orgMetrics[$organization->id] = $metrics;
            $globalAttendanceSum += $metrics['avg_attendance_rate'];

            if ($metrics['avg_evaluation_score'] > 0) {
                $globalEvaluationSum += $metrics['avg_evaluation_score'];
                $globalEvaluationCount++;
            }
        }

        $organizationCount = $organizations->count();
        $summary = [
            'total_orgs' => $organizationCount,
            'avg_attendance_rate' => $organizationCount > 0 ? round($globalAttendanceSum / $organizationCount, 2) : 0,
            'avg_eval_score' => $globalEvaluationCount > 0 ? round($globalEvaluationSum / $globalEvaluationCount, 2) : 0,
        ];
        $ranked = $organizations
            ->map(function (Organization $organization) use ($orgMetrics): array {
                $metrics = $orgMetrics[$organization->id];
                $compositeScore = ($metrics['avg_attendance_rate'] * 0.5)
                    + ($metrics['avg_evaluation_score'] > 0 ? ($metrics['avg_evaluation_score'] / 5.0 * 100 * 0.5) : 0);

                return array_merge(
                    ['org' => $organization],
                    $metrics,
                    ['composite_score' => round($compositeScore, 2)],
                );
            })
            ->sortByDesc('composite_score')
            ->values();

        $programsForComparison = Program::query()
            ->with(['college', 'organizations'])
            ->when($selectedCollegeId, fn ($query, $collegeId) => $query->where('college_id', $collegeId))
            ->when($selectedProgramId, fn ($query, $programId) => $query->where('id', $programId))
            ->orderBy('name')
            ->get();
        $programComparison = $programsForComparison
            ->map(fn (Program $program): array => $this->organizationAnalytics->programMetrics(
                $program,
                $selectedSchoolYear,
                $selectedSemester,
                $selectedEventType,
                $selectedYearLevel,
            ))
            ->sortByDesc('composite_score')
            ->values()
            ->all();

        $orgsByCollege = [];
        $programsByOrg = [];
        $allOrganizations = Organization::query()->with('programs')->orderBy('name')->get();

        foreach ($allOrganizations as $organization) {
            $orgsByCollege[$organization->college_id ?? 'university_wide'][] = [
                'id' => $organization->id,
                'name' => $organization->name,
                'acronym' => $organization->acronym,
            ];

            foreach ($organization->programs as $program) {
                $programsByOrg[$organization->id][] = [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                ];
            }
        }

        $programsByCollege = [];

        foreach (Program::query()->orderBy('name')->get() as $program) {
            $programsByCollege[$program->college_id][] = [
                'id' => $program->id,
                'name' => $program->name,
                'code' => $program->code,
            ];
        }

        $yearLevelOptions = YearLevels::options();

        return compact(
            'organizations',
            'orgMetrics',
            'summary',
            'ranked',
            'user',
            'filterColleges',
            'filterOrganizations',
            'filterPrograms',
            'schoolYears',
            'programComparison',
            'orgsByCollege',
            'programsByCollege',
            'programsByOrg',
            'selectedCollegeId',
            'selectedOrgId',
            'selectedProgramId',
            'selectedYearLevel',
            'selectedSchoolYear',
            'selectedSemester',
            'selectedEventType',
            'yearLevelOptions',
            'scope',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterValue(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
