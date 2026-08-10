<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\PendingStudentLink;
use App\Models\RawScan;
use App\Models\ScannerSession;
use App\Models\User;
use App\Support\EventEvaluationForm;
use App\Support\YearLevels;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventAnalyticsService
{
    public function __construct(
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventAnalyticsCache $cache,
    ) {}

    public function summary(Event $event): array
    {
        return $this->cache->remember($event, fn (): array => $this->buildSummary($event));
    }

    private function buildSummary(Event $event): array
    {
        $event->loadMissing([
            'organization.college',
            'parentEvent',
            'eventDays' => fn ($query) => $query->orderBy('date')->orderBy('day_number'),
            'evaluationFormVersion',
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.program',
        ]);

        $analyticsScopes = $this->runtimeAnalyticsScopes($event);
        $audience = $this->audienceForEvent($event, $analyticsScopes);
        $attendanceRecords = AttendanceRecord::with(['eventDay', 'participationScope', 'student.organization', 'student.program'])
            ->where('event_id', $event->id)
            ->get();
        $evaluations = Evaluation::with(['student.organization', 'student.program'])
            ->where('event_id', $event->id)
            ->get();
        $scannerSync = $this->scannerSync($event);

        $attendance = $this->attendanceSummary($event, $attendanceRecords, $audience['ids']);
        $evaluationSummary = $this->evaluationSummary($event, $evaluations, $attendance['global_unique_attended'], $audience['count']);

        return [
            'event' => $this->eventPayload($event, $audience, $scannerSync),
            'attendance' => $attendance + [
                'participant_scopes' => $this->scopeRollups($event, $analyticsScopes, $attendanceRecords, $evaluations),
            ],
            'daily_attendance' => $this->dailyAttendance($event, $attendanceRecords, $audience['count']),
            'evaluations' => $evaluationSummary,
            'sentiment_comments' => $this->sentimentComments($evaluations),
            'participant_scopes' => $this->scopeRollups($event, $analyticsScopes, $attendanceRecords, $evaluations),
            'scanner_sync' => $scannerSync,
            'demographics' => $this->demographics($attendanceRecords),
            'year_levels' => $this->yearLevelDemographics($attendanceRecords),
        ];
    }

    public function participantRows(Event $event, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $event->loadMissing([
            'eventDays' => fn ($query) => $query->orderBy('date')->orderBy('day_number'),
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.program',
        ]);

        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
        $analyticsScopes = $this->runtimeAnalyticsScopes($event);
        $audience = $this->audienceForEvent($event, $analyticsScopes);
        $scopeAudienceMap = $this->scopeAudienceMap($analyticsScopes);
        $scopeMap = $analyticsScopes->keyBy('id');

        $attendanceRecords = AttendanceRecord::with(['eventDay', 'participationScope'])
            ->where('event_id', $event->id)
            ->get();
        $recordsByStudent = $attendanceRecords->groupBy('student_id');
        $evaluationsByStudent = Evaluation::where('event_id', $event->id)->get()->keyBy('student_id');
        $attendedIds = $attendanceRecords->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->values();
        $studentIds = $audience['ids']->merge($attendedIds)->unique()->values();

        $users = User::with(['organization', 'program', 'college'])
            ->whereIn('id', $studentIds)
            ->orderBy('name')
            ->get();

        $dayId = $filters['day_id'] ?? null;
        $rows = $users->map(function (User $user) use ($event, $recordsByStudent, $evaluationsByStudent, $audience, $scopeAudienceMap, $scopeMap, $dayId) {
            $studentRecords = $recordsByStudent->get($user->id, collect());
            $days = $this->participantDayStatuses($event, $studentRecords);
            $dayStatus = $dayId ? ($days->firstWhere('event_day_id', $dayId) ?: null) : null;
            $status = $dayStatus['status'] ?? $this->participantStatus($studentRecords);
            $scopeIds = $scopeAudienceMap
                ->filter(fn (Collection $ids) => $ids->contains($user->id))
                ->keys()
                ->merge($studentRecords->pluck('event_participation_scope_id')->filter()->unique())
                ->unique()
                ->values();
            $scopeNames = $scopeIds
                ->map(fn (string $scopeId) => $scopeMap->get($scopeId)?->displayName())
                ->filter()
                ->unique()
                ->values();
            $evaluation = $evaluationsByStudent->get($user->id);

            return [
                'student_id' => $user->id,
                'student_id_number' => $user->student_id_number,
                'name' => $user->name,
                'email' => $user->email,
                'program_code' => $user->program_code ?: $user->program?->code,
                'year_level' => $user->year_level,
                'year_level_label' => YearLevels::label($user->year_level),
                'organization' => $user->organization?->acronym ?: $user->organization?->name,
                'expected' => $audience['ids']->contains($user->id),
                'scope_ids' => $scopeIds,
                'scope_names' => $scopeNames,
                'attended' => $studentRecords->whereNotNull('time_in')->isNotEmpty(),
                'valid' => $studentRecords->where('valid', true)->isNotEmpty(),
                'force_validated' => $studentRecords->where('force_validated', true)->isNotEmpty(),
                'evaluation_submitted' => (bool) $evaluation,
                'status' => $status,
                'days' => $days,
                'day_status' => $dayStatus,
                'time_in' => $dayStatus['time_in'] ?? $this->dateTime($studentRecords->whereNotNull('time_in')->sortBy('time_in')->first()?->time_in),
                'time_out' => $dayStatus['time_out'] ?? $this->dateTime($studentRecords->whereNotNull('time_out')->sortByDesc('time_out')->first()?->time_out),
            ];
        });

        $rows = $this->filterParticipantRows($rows, $filters);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    private function eventPayload(Event $event, array $audience, array $scannerSync): array
    {
        $days = $event->eventDays;
        $schedule = $days->isNotEmpty()
            ? $days->map(fn ($day) => [
                'id' => $day->id,
                'label' => 'Day '.$day->day_number,
                'date' => $day->date?->toDateString(),
                'start_time' => $day->start_time,
                'end_time' => $day->end_time,
            ])->values()
            : collect();

        return [
            'id' => $event->id,
            'title' => $event->title,
            'status' => $event->status,
            'lifecycle_phase' => $event->lifecycle_phase,
            'proposal_category' => $event->proposal_category,
            'event_domain' => $event->event_domain,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'location_type' => $event->location_type,
            'location_details' => $event->location_details,
            'organizer' => [
                'id' => $event->organization?->id,
                'name' => $event->organization?->name,
                'acronym' => $event->organization?->acronym,
                'type' => $event->organization?->type,
                'college' => $event->organization?->college?->code,
            ],
            'parent_event' => $event->parentEvent ? [
                'id' => $event->parentEvent->id,
                'title' => $event->parentEvent->title,
            ] : null,
            'schedule' => $schedule,
            'target_demographics' => $event->target_demographics ?: [],
            'proposal_state' => [
                'hardcopy_submitted' => (bool) $event->hardcopy_submitted,
                'head_organization_signed' => (bool) $event->head_organization_signed,
                'adviser_signed' => (bool) $event->adviser_signed,
                'resolution_number' => $event->resolution_number,
                'evaluation_open' => (bool) $event->evaluation_open,
            ],
            'audience' => [
                'count' => $audience['count'],
                'source' => $audience['source'],
                'source_label' => $audience['source_label'],
                'scope_count' => $audience['scope_count'],
                'fallback_used' => $audience['fallback_used'],
            ],
            'scanner_health' => [
                'active_sessions' => $scannerSync['sessions']['active'],
                'revoked_sessions' => $scannerSync['sessions']['revoked'],
                'last_seen_at' => $scannerSync['sessions']['last_seen_at'],
                'pending_qr_links' => $scannerSync['pending_links']['pending'],
            ],
        ];
    }

    private function attendanceSummary(Event $event, Collection $records, Collection $audienceIds): array
    {
        $expected = $audienceIds->count();
        $attendedIds = $records->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->values();
        $validIds = $records->where('valid', true)->pluck('student_id')->filter()->unique()->values();
        $timeInRecords = $records->whereNotNull('time_in');
        $timeOutRecords = $records->whereNotNull('time_out');
        $recordedAbsent = $records->where('society_status', 'absent')->count();
        $estimatedAbsent = max($expected - $attendedIds->count(), 0);

        return [
            'total_demographic' => $expected,
            'expected_audience' => $expected,
            'denominator_label' => $expected.' people expected',
            'attended' => $attendedIds->count(),
            'global_unique_attended' => $attendedIds->count(),
            'missing_expected' => $estimatedAbsent,
            'attendance_rate' => $this->rate($attendedIds->count(), $expected),
            'valid_unique_attended' => $validIds->count(),
            'valid_attendance_rate' => $this->rate($validIds->count(), $expected),
            'invalid_or_pending_unique' => max($attendedIds->diff($validIds)->count(), 0),
            'valid_records' => $records->where('valid', true)->count(),
            'invalid_records' => $records->where('valid', false)->count(),
            'force_validated_records' => $records->where('force_validated', true)->count(),
            'time_in_records' => $timeInRecords->count(),
            'time_out_records' => $timeOutRecords->count(),
            'missing_time_out_records' => $timeInRecords->whereNull('time_out')->count(),
            'status_counts' => [
                'present_on_time' => $records->where('society_status', 'present_on_time')->count(),
                'late' => $records->where('society_status', 'late')->count(),
                'late_cutoff' => $records->where('society_status', 'late_cutoff')->count(),
                'absent' => max($recordedAbsent, $estimatedAbsent),
                'recorded_absent' => $recordedAbsent,
            ],
            'left_early_records' => $records->where('left_early', true)->count(),
        ];
    }

    private function dailyAttendance(Event $event, Collection $records, int $expected): Collection
    {
        return $event->eventDays->map(function ($day) use ($records, $expected) {
            $dayRecords = $records->where('event_day_id', $day->id);
            $attended = $dayRecords->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->count();
            $valid = $dayRecords->where('valid', true)->pluck('student_id')->filter()->unique()->count();
            $recordedAbsent = $dayRecords->where('society_status', 'absent')->count();
            $estimatedAbsent = max($expected - $attended, 0);

            return [
                'id' => $day->id,
                'label' => 'Day '.$day->day_number,
                'date' => $day->date?->toDateString(),
                'start_time' => $day->start_time,
                'end_time' => $day->end_time,
                'expected_audience' => $expected,
                'attended' => $attended,
                'attendance_rate' => $this->rate($attended, $expected),
                'valid_unique_attended' => $valid,
                'valid_attendance_rate' => $this->rate($valid, $expected),
                'time_in_records' => $dayRecords->whereNotNull('time_in')->count(),
                'time_out_records' => $dayRecords->whereNotNull('time_out')->count(),
                'missing_time_out_records' => $dayRecords->whereNotNull('time_in')->whereNull('time_out')->count(),
                'status_counts' => [
                    'present_on_time' => $dayRecords->where('society_status', 'present_on_time')->count(),
                    'late' => $dayRecords->where('society_status', 'late')->count(),
                    'late_cutoff' => $dayRecords->where('society_status', 'late_cutoff')->count(),
                    'absent' => max($recordedAbsent, $estimatedAbsent),
                    'recorded_absent' => $recordedAbsent,
                ],
                'left_early_records' => $dayRecords->where('left_early', true)->count(),
            ];
        })->values();
    }

    private function evaluationSummary(Event $event, Collection $evaluations, int $attended, int $expected): array
    {
        $questions = EventEvaluationForm::questionMapForEvent($event);
        $uniqueEvaluators = $evaluations->pluck('student_id')->filter()->unique()->count();
        $allScores = collect();
        $categoryMetrics = collect($questions)->map(function (array $meta, string $key) use ($evaluations, $allScores) {
            $scores = $evaluations
                ->map(fn (Evaluation $evaluation) => is_array($evaluation->section_scores) ? ($evaluation->section_scores[$key] ?? null) : null)
                ->filter(fn ($score) => is_numeric($score))
                ->map(fn ($score) => (int) $score);

            $scores->each(fn (int $score) => $allScores->push($score));

            $distribution = collect(range(1, 5))->mapWithKeys(
                fn (int $score) => [$score => $scores->filter(fn (int $value) => $value === $score)->count()]
            );

            return [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'average' => $scores->isNotEmpty() ? round($scores->avg(), 2) : 0,
                'responses' => $scores->count(),
                'distribution' => $distribution,
            ];
        })->values();

        $sentiments = $this->sentimentCounts($evaluations);
        $confidenceScores = $evaluations->pluck('sentiment_score')->filter(fn ($score) => $score !== null);

        return [
            'total_submitted' => $evaluations->count(),
            'unique_evaluators' => $uniqueEvaluators,
            'submitted' => $uniqueEvaluators,
            'missing_from_attended' => max($attended - $uniqueEvaluators, 0),
            'missing_from_expected' => max($expected - $uniqueEvaluators, 0),
            'response_rate_attended' => $this->rate($uniqueEvaluators, $attended),
            'response_rate_expected' => $this->rate($uniqueEvaluators, $expected),
            'average_rating' => $allScores->isNotEmpty() ? round($allScores->avg(), 2) : 0,
            'category_averages' => $categoryMetrics,
            'rating_distribution' => $categoryMetrics->mapWithKeys(fn (array $metric) => [$metric['key'] => $metric['distribution']]),
            'sentiments' => $sentiments,
            'sentiment_confidence' => [
                'average' => $confidenceScores->isNotEmpty() ? round($confidenceScores->avg(), 3) : 0,
                'minimum' => $confidenceScores->isNotEmpty() ? round($confidenceScores->min(), 3) : 0,
                'maximum' => $confidenceScores->isNotEmpty() ? round($confidenceScores->max(), 3) : 0,
                'processed' => $confidenceScores->count(),
            ],
            'sentiment_health' => $this->sentimentHealth($sentiments, $evaluations->count()),
        ];
    }

    private function sentimentCounts(Collection $evaluations): array
    {
        $counts = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'unprocessed' => 0,
        ];

        foreach ($evaluations as $evaluation) {
            $sentiment = $evaluation->sentiment ?: 'unprocessed';
            if (! array_key_exists($sentiment, $counts)) {
                $sentiment = 'unprocessed';
            }
            $counts[$sentiment]++;
        }

        return $counts;
    }

    private function sentimentHealth(array $sentiments, int $total): array
    {
        if ($total <= 0) {
            return [
                'label' => 'No comments',
                'tone' => 'neutral',
                'negative_rate' => 0,
            ];
        }

        $negativeRate = $this->rate($sentiments['negative'], $total);
        $positiveRate = $this->rate($sentiments['positive'], $total);

        if ($negativeRate >= 25) {
            return [
                'label' => 'Needs review',
                'tone' => 'negative',
                'negative_rate' => $negativeRate,
            ];
        }

        if ($positiveRate >= 60) {
            return [
                'label' => 'Healthy',
                'tone' => 'positive',
                'negative_rate' => $negativeRate,
            ];
        }

        return [
            'label' => 'Mixed',
            'tone' => 'neutral',
            'negative_rate' => $negativeRate,
        ];
    }

    private function sentimentComments(Collection $evaluations): Collection
    {
        $priority = [
            'negative' => 0,
            'unprocessed' => 1,
            'neutral' => 2,
            'positive' => 3,
        ];

        return $evaluations
            ->filter(fn (Evaluation $evaluation) => filled($evaluation->open_comment))
            ->sortBy(fn (Evaluation $evaluation) => sprintf(
                '%02d-%0.3f',
                $priority[$evaluation->sentiment ?: 'unprocessed'] ?? 1,
                $evaluation->sentiment_score ?? 0
            ))
            ->take(40)
            ->map(fn (Evaluation $evaluation) => [
                'student_id' => $evaluation->student_id,
                'student_name' => $evaluation->student?->name,
                'program_code' => $evaluation->student?->program_code ?: $evaluation->student?->program?->code,
                'comment' => $evaluation->open_comment,
                'sentiment' => $evaluation->sentiment ?: 'unprocessed',
                'confidence' => $evaluation->sentiment_score,
                'submitted_at' => $this->dateTime($evaluation->submitted_at),
            ])
            ->values();
    }

    private function scopeRollups(Event $event, Collection $analyticsScopes, Collection $records, Collection $evaluations): Collection
    {
        return $analyticsScopes->map(function (EventParticipationScope $scope) use ($event, $records, $evaluations) {
            $audienceIds = $this->audienceIdsForScope($scope);
            $scopeRecords = $records->where('event_participation_scope_id', $scope->id);
            $attended = $scopeRecords->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->count();
            $valid = $scopeRecords->where('valid', true)->pluck('student_id')->filter()->unique()->count();
            $evaluationCount = $evaluations
                ->filter(fn (Evaluation $evaluation) => $audienceIds->contains($evaluation->student_id))
                ->pluck('student_id')
                ->unique()
                ->count();

            $assignments = EventScannerAssignment::where('event_id', $event->id)
                ->where('event_participation_scope_id', $scope->id)
                ->count();
            $sessions = ScannerSession::where('event_id', $event->id)
                ->where('event_participation_scope_id', $scope->id)
                ->count();
            $rawScans = RawScan::where('event_id', $event->id)
                ->where('event_participation_scope_id', $scope->id)
                ->count();

            return [
                'id' => $scope->id,
                'name' => $scope->displayName(),
                'participation_role' => $scope->participation_role,
                'participant_type' => $scope->participant_type,
                'capture_mode' => $scope->capture_mode,
                'ppa_status' => $scope->ppa_status,
                'analytics_rollup_enabled' => (bool) $scope->analytics_rollup_enabled,
                'registered' => $audienceIds->count(),
                'expected_audience' => $audienceIds->count(),
                'attended' => $attended,
                'attendance_rate' => $this->rate($attended, $audienceIds->count()),
                'valid_attendance' => $valid,
                'valid_attendance_records' => $scopeRecords->where('valid', true)->count(),
                'valid_attendance_rate' => $this->rate($valid, $audienceIds->count()),
                'evaluation_submissions' => $evaluationCount,
                'evaluation_rate' => $this->rate($evaluationCount, $audienceIds->count()),
                'scanner_assignments' => $assignments,
                'scanner_sessions' => $sessions,
                'raw_scans' => $rawScans,
            ];
        })->values();
    }

    private function scannerSync(Event $event): array
    {
        $assignments = EventScannerAssignment::with(['scannerUser', 'participationScope'])
            ->where('event_id', $event->id)
            ->get();
        $sessions = ScannerSession::with(['scannerUser', 'participationScope'])
            ->where('event_id', $event->id)
            ->get();
        $rawScans = RawScan::where('event_id', $event->id)->get();
        $pendingLinks = PendingStudentLink::where('event_id', $event->id)->get();
        $lastSeen = $sessions->pluck('last_seen_at')->filter()->sort()->last();
        $lastScan = $rawScans
            ->map(fn (RawScan $scan) => $scan->server_received_at ?: $scan->scanned_at)
            ->filter()
            ->sort()
            ->last();

        return [
            'assignments' => [
                'total' => $assignments->count(),
                'active' => $assignments->where('status', EventScannerAssignment::STATUS_ACTIVE)->count(),
                'revoked' => $assignments->where('status', EventScannerAssignment::STATUS_REVOKED)->count(),
                'expired' => $assignments->where('status', EventScannerAssignment::STATUS_EXPIRED)->count(),
                'rows' => $assignments->map(fn (EventScannerAssignment $assignment) => [
                    'id' => $assignment->id,
                    'label' => $assignment->label,
                    'status' => $assignment->status,
                    'scanner_name' => $assignment->scannerUser?->name,
                    'scope_name' => $assignment->participationScope?->displayName(),
                    'max_devices' => $assignment->max_devices,
                    'last_activated_at' => $this->dateTime($assignment->last_activated_at),
                    'expires_at' => $this->dateTime($assignment->expires_at),
                    'revoked_at' => $this->dateTime($assignment->revoked_at),
                ])->values(),
            ],
            'sessions' => [
                'total' => $sessions->count(),
                'active' => $sessions->where('status', ScannerSession::STATUS_ACTIVE)->count(),
                'revoked' => $sessions->where('status', ScannerSession::STATUS_REVOKED)->count(),
                'expired' => $sessions->where('status', ScannerSession::STATUS_EXPIRED)->count(),
                'last_seen_at' => $this->dateTime($lastSeen),
                'rows' => $sessions->map(fn (ScannerSession $session) => [
                    'id' => $session->id,
                    'label' => $session->label,
                    'device_id' => $session->device_id,
                    'status' => $session->status,
                    'scanner_name' => $session->scannerUser?->name,
                    'scope_name' => $session->participationScope?->displayName(),
                    'issued_at' => $this->dateTime($session->issued_at),
                    'activated_at' => $this->dateTime($session->activated_at),
                    'expires_at' => $this->dateTime($session->expires_at),
                    'revoked_at' => $this->dateTime($session->revoked_at),
                    'last_seen_at' => $this->dateTime($session->last_seen_at),
                ])->values(),
            ],
            'raw_scans' => [
                'total' => $rawScans->count(),
                'manual_entries' => $rawScans->where('manual_entry', true)->count(),
                'unresolved' => $rawScans->whereNull('student_id')->count(),
                'processed' => $rawScans->whereNotNull('processed_at')->count(),
                'rejected' => $rawScans->whereNotNull('rejection_reason')->count(),
                'last_scan_at' => $this->dateTime($lastScan),
            ],
            'pending_links' => [
                'total' => $pendingLinks->count(),
                'pending' => $pendingLinks->where('status', 'pending')->count(),
                'resolved' => $pendingLinks->where('status', 'resolved')->count(),
                'flagged' => $pendingLinks->where('status', 'flagged')->count(),
            ],
        ];
    }

    private function demographics(Collection $records): Collection
    {
        $attendedIds = $records->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->values();

        if ($attendedIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $attendedIds)
            ->select(DB::raw("coalesce(program_code, 'N/A') as program_code"), DB::raw('count(*) as count'))
            ->groupBy('program_code')
            ->orderBy('program_code')
            ->get()
            ->map(fn ($row) => [
                'program_code' => $row->program_code,
                'count' => (int) $row->count,
            ]);
    }

    private function yearLevelDemographics(Collection $records): Collection
    {
        $attendedIds = $records->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->values();

        if ($attendedIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $attendedIds)
            ->select('year_level', DB::raw('count(*) as count'))
            ->groupBy('year_level')
            ->orderBy('year_level')
            ->get()
            ->map(fn ($row) => [
                'year_level' => $row->year_level,
                'label' => YearLevels::label($row->year_level),
                'count' => (int) $row->count,
            ]);
    }

    private function participantDayStatuses(Event $event, Collection $records): Collection
    {
        return $event->eventDays->map(function ($day) use ($records) {
            $record = $records->where('event_day_id', $day->id)->sortByDesc('time_in')->first();

            return [
                'event_day_id' => $day->id,
                'label' => 'Day '.$day->day_number,
                'date' => $day->date?->toDateString(),
                'status' => $record ? $this->recordStatus($record) : 'absent',
                'valid' => (bool) ($record?->valid ?? false),
                'time_in' => $this->dateTime($record?->time_in),
                'time_out' => $this->dateTime($record?->time_out),
            ];
        })->values();
    }

    private function participantStatus(Collection $records): string
    {
        if ($records->whereNotNull('time_in')->isEmpty()) {
            return 'absent';
        }

        if ($records->where('society_status', 'late_cutoff')->isNotEmpty()) {
            return 'late_cutoff';
        }

        if ($records->where('left_early', true)->isNotEmpty()) {
            return 'left_early';
        }

        if ($records->where('society_status', 'late')->isNotEmpty()) {
            return 'late';
        }

        return 'present_on_time';
    }

    private function recordStatus(AttendanceRecord $record): string
    {
        if ($record->left_early) {
            return 'left_early';
        }

        return $record->society_status ?: 'present_on_time';
    }

    private function filterParticipantRows(Collection $rows, array $filters): Collection
    {
        $scopeId = $filters['scope_id'] ?? null;
        $dayId = $filters['day_id'] ?? null;
        $status = $filters['status'] ?? null;
        $validation = $filters['validation'] ?? null;
        $yearLevel = $filters['year_level'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));

        return $rows
            ->filter(function (array $row) use ($scopeId, $dayId, $status, $validation, $yearLevel, $search) {
                if ($scopeId && ! $row['scope_ids']->contains($scopeId)) {
                    return false;
                }

                if ($dayId && ! $row['day_status']) {
                    return false;
                }

                $effectiveStatus = $dayId ? ($row['day_status']['status'] ?? $row['status']) : $row['status'];
                if ($status && $status !== 'all' && $effectiveStatus !== $status) {
                    if (! ($status === 'attended' && $row['attended'])) {
                        return false;
                    }
                }

                if ($validation === 'valid' && ! $row['valid']) {
                    return false;
                }

                if ($validation === 'invalid' && (! $row['attended'] || $row['valid'])) {
                    return false;
                }

                if ($validation === 'evaluated' && ! $row['evaluation_submitted']) {
                    return false;
                }

                if ($validation === 'not_evaluated' && $row['evaluation_submitted']) {
                    return false;
                }

                if ($yearLevel && $row['year_level'] !== $yearLevel) {
                    return false;
                }

                if ($search !== '') {
                    $haystack = strtolower(implode(' ', array_filter([
                        $row['name'],
                        $row['email'],
                        $row['student_id_number'],
                        $row['program_code'],
                        $row['year_level_label'],
                        $row['organization'],
                    ])));

                    return str_contains($haystack, strtolower($search));
                }

                return true;
            })
            ->values();
    }

    private function runtimeAnalyticsScopes(Event $event): Collection
    {
        return $event->participationScopes
            ->filter(fn (EventParticipationScope $scope) => $scope->status === EventParticipationScope::STATUS_ACTIVE)
            ->filter(fn (EventParticipationScope $scope) => $scope->analytics_rollup_enabled)
            ->filter(fn (EventParticipationScope $scope) => ! $this->participationScopes->runtimeGuard($scope))
            ->values();
    }

    private function audienceForEvent(Event $event, Collection $analyticsScopes): array
    {
        if ($analyticsScopes->isNotEmpty()) {
            $ids = $this->scopeAudienceMap($analyticsScopes)
                ->flatMap(fn (Collection $scopeIds) => $scopeIds)
                ->unique()
                ->values();

            return [
                'ids' => $ids,
                'count' => $ids->count(),
                'source' => 'participation_scopes',
                'source_label' => $analyticsScopes->count().' active analytics-enabled scope(s)',
                'scope_count' => $analyticsScopes->count(),
                'fallback_used' => false,
            ];
        }

        $ids = $this->studentAudienceQueryForEvent($event)
            ->pluck('users.id')
            ->unique()
            ->values();

        return [
            'ids' => $ids,
            'count' => $ids->count(),
            'source' => 'legacy_event_audience',
            'source_label' => 'Legacy event audience fallback',
            'scope_count' => 0,
            'fallback_used' => true,
        ];
    }

    private function scopeAudienceMap(Collection $analyticsScopes): Collection
    {
        return $analyticsScopes->mapWithKeys(
            fn (EventParticipationScope $scope) => [$scope->id => $this->audienceIdsForScope($scope)]
        );
    }

    private function audienceIdsForScope(EventParticipationScope $scope): Collection
    {
        return $this->participationScopes
            ->audienceQuery($scope)
            ->pluck('users.id')
            ->unique()
            ->values();
    }

    private function studentAudienceQueryForEvent(Event $event)
    {
        $query = User::role('Student');
        $eventOrg = $event->organization;

        if (! $eventOrg) {
            return $query->whereRaw('1 = 0');
        }

        if ($eventOrg->type === 'society') {
            return $query->where('organization_id', $eventOrg->id);
        }

        if ($eventOrg->type === 'lsg') {
            return $query->whereHas('organization', function ($orgQuery) use ($eventOrg): void {
                $orgQuery->where('college_id', $eventOrg->college_id);
            });
        }

        if ($eventOrg->type === 'usg') {
            return $query;
        }

        return $query->where('organization_id', $eventOrg->id);
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0;
    }

    private function dateTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i')
            : (string) $value;
    }
}
