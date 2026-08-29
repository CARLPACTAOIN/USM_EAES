<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use App\Support\EventEvaluationForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrganizationAnalyticsService
{
    public function __construct(private readonly ParticipationScopeService $participationScopes) {}

    /**
     * @return array{
     *     total_events: int,
     *     total_members: int,
     *     active_members: int,
     *     active_member_ratio: float,
     *     avg_attendance_rate: float,
     *     avg_valid_attendance: float,
     *     avg_evaluation_score: float,
     *     eval_response_rate: float,
     *     sentiment_positive: float,
     *     sentiment_negative: float,
     *     total_late: int,
     *     total_absent: int,
     *     total_left_early: int,
     *     event_breakdown: list<array{id: string, title: string, start_date: string|null, attended: int, attendance_rate: float, eval_score: float, eval_responses: int}>,
     *     linked_programs_metrics: list<array{program: Program, avg_attendance_rate: float, avg_evaluation_score: float}>
     * }
     */
    public function organizationMetrics(
        Organization $organization,
        ?string $programId = null,
        ?string $schoolYear = null,
        ?string $semester = null,
        ?string $eventType = null,
        ?string $yearLevel = null,
    ): array {
        $events = $this->eligibleOrganizationEventsQuery($organization, $schoolYear, $semester, $eventType)
            ->with(['evaluationFormVersion', 'organization', 'participationScopes.organization'])
            ->get();
        $eventIds = $events->pluck('id');
        $memberIds = $this->organizationMemberIds($organization, $programId, $yearLevel);
        $audienceIdsByEvent = $events->mapWithKeys(
            fn (Event $event): array => [$event->id => $this->audienceIdsForEvent($event, $programId, $yearLevel)]
        );
        $allAudienceIds = $audienceIdsByEvent
            ->flatMap(fn (Collection $audienceIds): Collection => $audienceIds)
            ->unique()
            ->values();
        $analyticsStudentIds = $allAudienceIds->merge($memberIds)->unique()->values();
        $attendanceByEvent = AttendanceRecord::query()
            ->whereIn('event_id', $eventIds)
            ->whereIn('student_id', $analyticsStudentIds)
            ->get()
            ->groupBy('event_id');
        $evaluationsByEvent = Evaluation::query()
            ->whereIn('event_id', $eventIds)
            ->whereIn('student_id', $allAudienceIds)
            ->get()
            ->groupBy('event_id');

        $attendanceRateSum = 0.0;
        $validAttendanceRateSum = 0.0;
        $evaluationScores = collect();
        $totalEvaluationResponses = 0;
        $totalPossibleEvaluations = 0;
        $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $totalLate = 0;
        $totalAbsent = 0;
        $totalLeftEarly = 0;
        $eventBreakdown = [];

        foreach ($events as $event) {
            $audienceIds = $audienceIdsByEvent->get($event->id, collect());
            $audienceCount = $audienceIds->count();
            $records = $attendanceByEvent->get($event->id, collect())
                ->whereIn('student_id', $audienceIds);
            $evaluations = $evaluationsByEvent->get($event->id, collect())
                ->whereIn('student_id', $audienceIds);
            $attendedCount = $records
                ->whereNotNull('time_in')
                ->pluck('student_id')
                ->filter()
                ->unique()
                ->count();
            $validCount = $records
                ->where('valid', true)
                ->pluck('student_id')
                ->filter()
                ->unique()
                ->count();
            $eventAttendanceRate = $this->rate($attendedCount, $audienceCount);
            $eventValidAttendanceRate = $this->rate($validCount, $audienceCount);
            $eventScores = $this->numericEvaluationScores($event, $evaluations);
            $eventEvaluationScore = $eventScores->isNotEmpty() ? round((float) $eventScores->avg(), 2) : 0.0;

            $attendanceRateSum += $eventAttendanceRate;
            $validAttendanceRateSum += $eventValidAttendanceRate;
            $evaluationScores = $evaluationScores->merge($eventScores);
            $totalEvaluationResponses += $evaluations->count();
            $totalPossibleEvaluations += $audienceCount;
            $totalLate += $records->whereIn('society_status', ['late', 'late_cutoff'])->count();
            $totalAbsent += $records->where('society_status', 'absent')->count();
            $totalLeftEarly += $records->where('left_early', true)->count();

            foreach ($evaluations as $evaluation) {
                if (is_string($evaluation->sentiment) && array_key_exists($evaluation->sentiment, $sentimentCounts)) {
                    $sentimentCounts[$evaluation->sentiment]++;
                }
            }

            $eventBreakdown[] = [
                'id' => $event->id,
                'title' => $event->title,
                'start_date' => $event->start_date?->format('M d, Y'),
                'attended' => $attendedCount,
                'attendance_rate' => $eventAttendanceRate,
                'eval_score' => $eventEvaluationScore,
                'eval_responses' => $evaluations->count(),
            ];
        }

        $totalEvents = $events->count();
        $totalMembers = $memberIds->count();
        $activeMembers = $attendanceByEvent
            ->flatten(1)
            ->whereNotNull('time_in')
            ->pluck('student_id')
            ->filter()
            ->unique()
            ->intersect($memberIds)
            ->count();
        $totalSentiments = array_sum($sentimentCounts);
        $linkedProgramsMetrics = [];

        if ($programId === null) {
            foreach ($organization->programs()->orderBy('name')->get() as $program) {
                $programMetrics = $this->organizationMetrics(
                    $organization,
                    $program->id,
                    $schoolYear,
                    $semester,
                    $eventType,
                    $yearLevel,
                );
                $linkedProgramsMetrics[] = [
                    'program' => $program,
                    'avg_attendance_rate' => $programMetrics['avg_attendance_rate'],
                    'avg_evaluation_score' => $programMetrics['avg_evaluation_score'],
                ];
            }
        }

        return [
            'total_events' => $totalEvents,
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'active_member_ratio' => $this->rate($activeMembers, $totalMembers),
            'avg_attendance_rate' => $totalEvents > 0 ? round($attendanceRateSum / $totalEvents, 2) : 0.0,
            'avg_valid_attendance' => $totalEvents > 0 ? round($validAttendanceRateSum / $totalEvents, 2) : 0.0,
            'avg_evaluation_score' => $evaluationScores->isNotEmpty() ? round((float) $evaluationScores->avg(), 2) : 0.0,
            'eval_response_rate' => $this->rate($totalEvaluationResponses, $totalPossibleEvaluations),
            'sentiment_positive' => $this->rate($sentimentCounts['positive'], $totalSentiments, 1),
            'sentiment_negative' => $this->rate($sentimentCounts['negative'], $totalSentiments, 1),
            'total_late' => $totalLate,
            'total_absent' => $totalAbsent,
            'total_left_early' => $totalLeftEarly,
            'event_breakdown' => $eventBreakdown,
            'linked_programs_metrics' => $linkedProgramsMetrics,
        ];
    }

    /**
     * @return array{
     *     program: Program,
     *     total_events: int,
     *     total_members: int,
     *     avg_attendance_rate: float,
     *     avg_valid_attendance: float,
     *     avg_evaluation_score: float,
     *     eval_response_rate: float,
     *     sentiment_positive: float,
     *     sentiment_negative: float,
     *     total_late: int,
     *     total_absent: int,
     *     total_left_early: int,
     *     composite_score: float
     * }
     */
    public function programMetrics(
        Program $program,
        ?string $schoolYear = null,
        ?string $semester = null,
        ?string $eventType = null,
        ?string $yearLevel = null,
    ): array {
        $eventsQuery = Event::query()
            ->where('status', Event::STATUS_COMPLETED)
            ->where(function (Builder $query): void {
                $query->whereNull('event_domain')->orWhere('event_domain', '!=', 'hr');
            })
            ->whereHas('organization', fn (Builder $query): Builder => $query->where('type', '!=', 'hr_unit'));
        $this->applyEventFilters($eventsQuery, $schoolYear, $semester, $eventType);
        $events = $eventsQuery->with('evaluationFormVersion')->get();
        $studentIds = User::role('Student')
            ->where('program_id', $program->id)
            ->when($yearLevel, fn (Builder $query, string $value): Builder => $query->where('year_level', $value))
            ->distinct()
            ->pluck('users.id');
        $totalMembers = $studentIds->count();
        $attendanceByEvent = AttendanceRecord::query()
            ->whereIn('event_id', $events->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('event_id');
        $evaluationsByEvent = Evaluation::query()
            ->whereIn('event_id', $events->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('event_id');
        $participatingEventIds = $attendanceByEvent->keys();
        $matchedEvents = $events->whereIn('id', $participatingEventIds);
        $attendanceRateSum = 0.0;
        $validAttendanceRateSum = 0.0;
        $evaluationScores = collect();
        $totalEvaluationResponses = 0;
        $totalPossibleEvaluations = 0;
        $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $totalLate = 0;
        $totalAbsent = 0;
        $totalLeftEarly = 0;

        foreach ($matchedEvents as $event) {
            $records = $attendanceByEvent->get($event->id, collect());
            $evaluations = $evaluationsByEvent->get($event->id, collect());
            $attendanceRateSum += $this->rate(
                $records->whereNotNull('time_in')->pluck('student_id')->filter()->unique()->count(),
                $totalMembers,
            );
            $validAttendanceRateSum += $this->rate(
                $records->where('valid', true)->pluck('student_id')->filter()->unique()->count(),
                $totalMembers,
            );
            $evaluationScores = $evaluationScores->merge($this->numericEvaluationScores($event, $evaluations));
            $totalEvaluationResponses += $evaluations->count();
            $totalPossibleEvaluations += $totalMembers;
            $totalLate += $records->whereIn('society_status', ['late', 'late_cutoff'])->count();
            $totalAbsent += $records->where('society_status', 'absent')->count();
            $totalLeftEarly += $records->where('left_early', true)->count();

            foreach ($evaluations as $evaluation) {
                if (is_string($evaluation->sentiment) && array_key_exists($evaluation->sentiment, $sentimentCounts)) {
                    $sentimentCounts[$evaluation->sentiment]++;
                }
            }
        }

        $totalEvents = $matchedEvents->count();
        $averageAttendanceRate = $totalEvents > 0 ? round($attendanceRateSum / $totalEvents, 2) : 0.0;
        $averageEvaluationScore = $evaluationScores->isNotEmpty() ? round((float) $evaluationScores->avg(), 2) : 0.0;
        $totalSentiments = array_sum($sentimentCounts);
        $compositeScore = ($averageAttendanceRate * 0.5)
            + ($averageEvaluationScore > 0 ? ($averageEvaluationScore / 5.0 * 100 * 0.5) : 0);

        return [
            'program' => $program,
            'total_events' => $totalEvents,
            'total_members' => $totalMembers,
            'avg_attendance_rate' => $averageAttendanceRate,
            'avg_valid_attendance' => $totalEvents > 0 ? round($validAttendanceRateSum / $totalEvents, 2) : 0.0,
            'avg_evaluation_score' => $averageEvaluationScore,
            'eval_response_rate' => $this->rate($totalEvaluationResponses, $totalPossibleEvaluations),
            'sentiment_positive' => $this->rate($sentimentCounts['positive'], $totalSentiments, 1),
            'sentiment_negative' => $this->rate($sentimentCounts['negative'], $totalSentiments, 1),
            'total_late' => $totalLate,
            'total_absent' => $totalAbsent,
            'total_left_early' => $totalLeftEarly,
            'composite_score' => round($compositeScore, 2),
        ];
    }

    private function eligibleOrganizationEventsQuery(
        Organization $organization,
        ?string $schoolYear,
        ?string $semester,
        ?string $eventType,
    ): Builder {
        $query = Event::query()
            ->whereBelongsTo($organization)
            ->where('status', Event::STATUS_COMPLETED)
            ->where(function (Builder $query): void {
                $query->whereNull('event_domain')->orWhere('event_domain', '!=', 'hr');
            });

        if ($organization->type === 'hr_unit') {
            $query->whereRaw('1 = 0');
        }

        $this->applyEventFilters($query, $schoolYear, $semester, $eventType);

        return $query;
    }

    private function organizationMemberIds(Organization $organization, ?string $programId, ?string $yearLevel): Collection
    {
        $query = User::role('Student')
            ->where(function (Builder $query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->orWhereIn('id', function ($membershipQuery) use ($organization): void {
                        $membershipQuery->select('user_id')
                            ->from('organization_memberships')
                            ->where('organization_id', $organization->id)
                            ->where('status', 'active');
                    });
            });
        $this->applyStudentFilters($query, $programId, $yearLevel);

        return $query->distinct()->pluck('users.id')->unique()->values();
    }

    private function audienceIdsForEvent(Event $event, ?string $programId, ?string $yearLevel): Collection
    {
        $organizerScope = $event->participationScopes->first(
            fn (EventParticipationScope $scope): bool => $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER
                && $scope->status === EventParticipationScope::STATUS_ACTIVE
                && $scope->analytics_rollup_enabled
        );
        $query = $organizerScope
            ? $this->participationScopes->audienceQuery($organizerScope, requireRuntimeApproval: false)
            : $this->legacyAudienceQuery($event);
        $this->applyStudentFilters($query, $programId, $yearLevel);

        return $query->distinct()->pluck('users.id')->unique()->values();
    }

    private function legacyAudienceQuery(Event $event): Builder
    {
        $query = User::role('Student');
        $organization = $event->organization;

        if (! $organization) {
            return $query->whereRaw('1 = 0');
        }

        if ($organization->type === 'society') {
            return $query->where('organization_id', $organization->id);
        }

        if ($organization->type === 'lsg') {
            return $query->whereHas('organization', function (Builder $query) use ($organization): void {
                $query->where('college_id', $organization->college_id);
            });
        }

        if ($organization->type === 'usg') {
            return $query;
        }

        return $query->where('organization_id', $organization->id);
    }

    private function applyStudentFilters(Builder $query, ?string $programId, ?string $yearLevel): void
    {
        $query
            ->when($programId, fn (Builder $query, string $value): Builder => $query->where('program_id', $value))
            ->when($yearLevel, fn (Builder $query, string $value): Builder => $query->where('year_level', $value));
    }

    private function applyEventFilters(
        Builder $query,
        ?string $schoolYear,
        ?string $semester,
        ?string $eventType,
    ): void {
        if ($schoolYear && preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches) === 1) {
            $startYear = $matches[1];
            $endYear = $matches[2];

            if ($semester === '1st Semester') {
                $query->whereBetween('start_date', ["{$startYear}-06-01", "{$startYear}-10-31"]);
            } elseif ($semester === '2nd Semester') {
                $query->whereBetween('start_date', ["{$startYear}-11-01", "{$endYear}-03-31"]);
            } elseif ($semester === 'Summer') {
                $query->whereBetween('start_date', ["{$endYear}-04-01", "{$endYear}-05-31"]);
            } else {
                $query->whereBetween('start_date', ["{$startYear}-06-01", "{$endYear}-05-31"]);
            }
        } elseif ($semester === '1st Semester') {
            $query->whereMonth('start_date', '>=', 6)->whereMonth('start_date', '<=', 10);
        } elseif ($semester === '2nd Semester') {
            $query->where(function (Builder $query): void {
                $query->whereMonth('start_date', '>=', 11)->orWhereMonth('start_date', '<=', 3);
            });
        } elseif ($semester === 'Summer') {
            $query->whereMonth('start_date', '>=', 4)->whereMonth('start_date', '<=', 5);
        }

        if ($eventType) {
            $query->where('proposal_category', $eventType);
        }
    }

    private function numericEvaluationScores(Event $event, Collection $evaluations): Collection
    {
        $questionKeys = array_keys(EventEvaluationForm::questionMapForEvent($event));

        return $evaluations
            ->flatMap(function (Evaluation $evaluation) use ($questionKeys): array {
                if (! is_array($evaluation->section_scores)) {
                    return [];
                }

                return collect($questionKeys)
                    ->map(fn (string $key): mixed => $evaluation->section_scores[$key] ?? null)
                    ->filter(fn (mixed $score): bool => is_numeric($score))
                    ->map(fn (mixed $score): int => (int) $score)
                    ->values()
                    ->all();
            })
            ->values();
    }

    private function rate(int $numerator, int $denominator, int $precision = 2): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, $precision) : 0;
    }
}
