<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class NlpQueryExecutor
{
    public function execute(User $user, array $parsedQuery): array
    {
        $targetTable = $parsedQuery['target_table'] ?? 'events';
        $filters = $parsedQuery['filters'] ?? [];
        $tableConfig = $this->allowedQueryTables();

        if (!array_key_exists($targetTable, $tableConfig)) {
            abort(403, 'Query target table is unauthorized or invalid.');
        }

        $config = $tableConfig[$targetTable];
        $selectColumns = array_map(
            fn (string $column): string => $targetTable . '.' . $column,
            $config['columns']
        );

        $dbQuery = DB::table($targetTable)->select($selectColumns);
        $this->applyTenantScope($dbQuery, $targetTable, $user);

        $appliedFilters = [];
        $ignoredFilters = [];

        foreach ($filters as $filter) {
            $field = $filter['field'] ?? '';
            $operator = strtoupper($filter['operator'] ?? '=');
            $value = $filter['value'] ?? null;

            if (!in_array($field, $config['columns'], true)) {
                $ignoredFilters[] = $filter;
                continue;
            }

            if (!in_array($operator, ['=', 'LIKE', '>', '<', '<=', '>='], true)) {
                $operator = '=';
            }

            if ($operator === 'LIKE') {
                $dbQuery->where($targetTable . '.' . $field, 'LIKE', '%' . $value . '%');
            } else {
                $dbQuery->where($targetTable . '.' . $field, $operator, $value);
            }

            $appliedFilters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return [
            'target_table' => $targetTable,
            'columns' => $config['columns'],
            'applied_filters' => $appliedFilters,
            'ignored_filters' => $ignoredFilters,
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
                    'parent_event_id',
                    'title',
                    'status',
                    'start_date',
                    'end_date',
                    'location_type',
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

    private function applyTenantScope($query, string $targetTable, User $user): void
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        if ($targetTable === 'events') {
            if ($user->hasRole('LSG Admin')) {
                $query->join('organizations as scope_orgs', 'events.organization_id', '=', 'scope_orgs.id')
                    ->where('scope_orgs.college_id', $user->organization?->college_id);
                return;
            }

            if ($user->hasRole('USG Admin')) {
                $query->join('organizations as scope_orgs', 'events.organization_id', '=', 'scope_orgs.id')
                    ->where(function ($scopeQuery) use ($user): void {
                        $scopeQuery
                            ->where('scope_orgs.type', 'usg')
                            ->orWhere('events.organization_id', $user->organization_id);
                    });
                return;
            }

            $query->where('events.organization_id', $user->organization_id);
            return;
        }

        $query->join('events as scope_events', $targetTable . '.event_id', '=', 'scope_events.id');

        if ($user->hasRole('LSG Admin')) {
            $query->join('organizations as scope_orgs', 'scope_events.organization_id', '=', 'scope_orgs.id')
                ->where('scope_orgs.college_id', $user->organization?->college_id);
            return;
        }

        if ($user->hasRole('USG Admin')) {
            $query->join('organizations as scope_orgs', 'scope_events.organization_id', '=', 'scope_orgs.id')
                ->where(function ($scopeQuery) use ($user): void {
                    $scopeQuery
                        ->where('scope_orgs.type', 'usg')
                        ->orWhere('scope_events.organization_id', $user->organization_id);
                });
            return;
        }

        $query->where('scope_events.organization_id', $user->organization_id);
    }
}
