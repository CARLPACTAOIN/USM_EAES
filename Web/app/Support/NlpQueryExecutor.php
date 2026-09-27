<?php

namespace App\Support;

use App\Models\SchoolYear;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Support\Facades\DB;

class NlpQueryExecutor
{
    public function execute(User $user, array $parsedQuery, ?string $selectedSchoolYear = null): array
    {
        $targetTable = $parsedQuery['target_table'] ?? 'events';
        $filters = $parsedQuery['filters'] ?? [];
        $tableConfig = $this->allowedQueryTables();

        if (! array_key_exists($targetTable, $tableConfig)) {
            abort(403, 'Query target table is unauthorized or invalid.');
        }

        $config = $tableConfig[$targetTable];
        $selectColumns = array_map(
            fn (string $column): string => $targetTable.'.'.$column,
            $config['columns']
        );

        $dbQuery = DB::table($targetTable)->select($selectColumns);
        $effectiveSchoolYear = $this->applyAuthorityAndSchoolYearScope($dbQuery, $targetTable, $user, $selectedSchoolYear, $filters);

        $appliedFilters = [];
        $ignoredFilters = [];

        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            $operator = strtoupper($filter['operator'] ?? '=');
            $value = $filter['value'] ?? null;

            if ($field === 'school_year' || $field === 'school_year_id') {
                // Already resolved and securely applied via authority projection
                $appliedFilters[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ];

                continue;
            }

            if (! in_array($field, $config['columns'], true)) {
                $ignoredFilters[] = $filter;

                continue;
            }

            if (! in_array($operator, ['=', 'LIKE', '>', '<', '<=', '>='], true)) {
                $operator = '=';
            }

            if ($operator === 'LIKE') {
                $dbQuery->where($targetTable.'.'.$field, 'LIKE', '%'.$value.'%');
            } else {
                $dbQuery->where($targetTable.'.'.$field, $operator, $value);
            }

            $appliedFilters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        $scope = app(AdminScopeService::class)->forUser($user);

        return [
            'target_table' => $targetTable,
            'columns' => $config['columns'],
            'applied_filters' => $appliedFilters,
            'ignored_filters' => $ignoredFilters,
            'school_year_id' => $effectiveSchoolYear?->id,
            'authority_projection' => $scope ? [
                'role' => $scope->roleName,
                'organization_id' => $scope->organizationId,
                'college_id' => $scope->collegeId,
                'department_id' => $scope->departmentId,
                'school_year_id' => $scope->schoolYearId,
            ] : null,
            'results' => $dbQuery->limit(50)->get(),
        ];
    }

    public function allowedQueryTables(): array
    {
        return [
            'events' => [
                'columns' => [
                    'id',
                    'organization_id',
                    'school_year_id',
                    'department_id',
                    'parent_event_id',
                    'title',
                    'status',
                    'start_date',
                    'end_date',
                    'location_type',
                    'college_name_snapshot',
                    'department_name_snapshot',
                    'program_name_snapshot',
                    'organization_name_snapshot',
                    'proponent_name_snapshot',
                    'created_at',
                    'updated_at',
                ],
            ],
            'attendance_records' => [
                'columns' => [
                    'id',
                    'event_id',
                    'event_day_id',
                    'student_id',
                    'time_in',
                    'time_out',
                    'society_status',
                    'competition_status',
                    'left_early',
                    'valid',
                    'force_validated',
                    'created_at',
                    'updated_at',
                ],
            ],
            'evaluations' => [
                'columns' => [
                    'id',
                    'event_id',
                    'student_id',
                    'sentiment',
                    'sentiment_score',
                    'submitted_at',
                    'created_at',
                    'updated_at',
                ],
            ],
        ];
    }

    private function applyAuthorityAndSchoolYearScope(
        $query,
        string $targetTable,
        User $user,
        ?string $selectedSchoolYear = null,
        array $filters = []
    ): ?SchoolYear {
        $scope = app(AdminScopeService::class)->forUser($user);

        if (! $scope) {
            $query->whereRaw('1 = 0');

            return null;
        }

        // 1. Resolve explicit school year from parameter or parsed query filters
        $explicitYearInput = $selectedSchoolYear;
        if (! filled($explicitYearInput)) {
            foreach ($filters as $filter) {
                if (in_array($filter['field'] ?? '', ['school_year', 'school_year_id'], true)) {
                    $explicitYearInput = (string) ($filter['value'] ?? '');
                    break;
                }
            }
        }

        $schoolYear = null;
        if (filled($explicitYearInput)) {
            $schoolYear = SchoolYear::where('id', $explicitYearInput)
                ->orWhere('label', $explicitYearInput)
                ->first();
        }

        // 2. Validate Authority Projection against requested School Year
        if (filled($scope->schoolYearId)) {
            if ($schoolYear && $schoolYear->id !== $scope->schoolYearId) {
                abort(403, 'Authority Projection does not permit querying outside assigned School Year.');
            }
            $schoolYear ??= SchoolYear::find($scope->schoolYearId);
        }

        // 3. Apply School Year filtering
        $eventAlias = $targetTable === 'events' ? 'events' : 'scope_events';

        if ($targetTable !== 'events') {
            $query->join('events as scope_events', $targetTable.'.event_id', '=', 'scope_events.id');
        }

        if ($schoolYear && filled($explicitYearInput)) {
            $query->where("{$eventAlias}.school_year_id", $schoolYear->id);
        } elseif (filled($scope->schoolYearId)) {
            $query->where(function ($q) use ($eventAlias, $scope): void {
                $q->where("{$eventAlias}.school_year_id", $scope->schoolYearId)
                    ->orWhereNull("{$eventAlias}.school_year_id");
            });
        }

        // 4. Apply Authority Projection tenancy boundaries
        if ($scope->isSuperAdmin()) {
            return $schoolYear;
        }

        if ($scope->isAcademicUnitAdmin()) {
            $query->where(function ($subQuery) use ($scope, $eventAlias): void {
                $subQuery->where("{$eventAlias}.department_id", $scope->departmentId)
                    ->orWhereIn("{$eventAlias}.organization_id", function ($orgQuery) use ($scope): void {
                        $orgQuery->select('id')->from('organizations')->where('department_id', $scope->departmentId);
                    });
            });

            return $schoolYear;
        }

        if ($scope->isLsgAdmin()) {
            $query->join('organizations as scope_orgs', "{$eventAlias}.organization_id", '=', 'scope_orgs.id')
                ->where('scope_orgs.college_id', $scope->collegeId);

            return $schoolYear;
        }

        if ($scope->isUsgAdmin()) {
            $query->join('organizations as scope_orgs', "{$eventAlias}.organization_id", '=', 'scope_orgs.id')
                ->where(function ($scopeQuery) use ($scope, $eventAlias): void {
                    $scopeQuery
                        ->where('scope_orgs.type', 'usg')
                        ->orWhere("{$eventAlias}.organization_id", $scope->organizationId);
                });

            return $schoolYear;
        }

        // Default: Society Admin or single organization scope
        $query->where("{$eventAlias}.organization_id", $scope->organizationId);

        return $schoolYear;
    }
}
