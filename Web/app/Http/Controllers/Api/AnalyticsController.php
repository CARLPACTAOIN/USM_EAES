<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Services\AdminScopeService;
use App\Services\ParticipationScopeService;
use App\Support\EventTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly ParticipationScopeService $participationScopes
    ) {
    }

    /**
     * Get real-time analytics for a specific event.
     *
     * @param  string  $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventAnalytics(Request $request, $eventId)
    {
        $event = Event::with(['organization', 'participationScopes.organization', 'participationScopes.college', 'participationScopes.program'])->findOrFail($eventId);

        // Tenancy Check
        EventTenantScope::authorize($event, $request->user());

        // 1. Attendance Metrics
        $analyticsScopes = $this->runtimeAnalyticsScopes($event);
        $globalAudienceIds = $analyticsScopes
            ->flatMap(fn (EventParticipationScope $scope) => $this->audienceIdsForScope($scope))
            ->unique()
            ->values();

        // Total registered participants across approved runtime scopes.
        $totalRegistered = $globalAudienceIds->isNotEmpty()
            ? $globalAudienceIds->count()
            : $this->studentAudienceQueryForEvent($event)->count();

        // Total students who timed in at least once
        $attendedCount = AttendanceRecord::where('event_id', $eventId)
            ->whereNotNull('time_in')
            ->distinct('student_id')
            ->count('student_id');

        $attendanceRate = $totalRegistered > 0 ? round(($attendedCount / $totalRegistered) * 100, 2) : 0;

        $scopeRollups = $analyticsScopes->map(function (EventParticipationScope $scope) use ($eventId) {
            $audienceIds = $this->audienceIdsForScope($scope);
            $registered = $audienceIds->count();
            $attended = AttendanceRecord::where('event_id', $eventId)
                ->where('event_participation_scope_id', $scope->id)
                ->whereNotNull('time_in')
                ->distinct('student_id')
                ->count('student_id');
            $validRecords = AttendanceRecord::where('event_id', $eventId)
                ->where('event_participation_scope_id', $scope->id)
                ->where('valid', true)
                ->count();
            $evaluationCount = $audienceIds->isEmpty()
                ? 0
                : Evaluation::where('event_id', $eventId)
                    ->whereIn('student_id', $audienceIds)
                    ->count();

            return [
                'id' => $scope->id,
                'name' => $scope->displayName(),
                'participation_role' => $scope->participation_role,
                'participant_type' => $scope->participant_type,
                'capture_mode' => $scope->capture_mode,
                'ppa_status' => $scope->ppa_status,
                'registered' => $registered,
                'attended' => $attended,
                'attendance_rate' => $registered > 0 ? round(($attended / $registered) * 100, 2) : 0,
                'valid_attendance_records' => $validRecords,
                'evaluation_submissions' => $evaluationCount,
            ];
        })->values();

        // 2. Evaluation Metrics
        $totalEvaluations = Evaluation::where('event_id', $eventId)->count();

        // Sentiment breakdown
        $sentiments = Evaluation::where('event_id', $eventId)
            ->select('sentiment', DB::raw('count(*) as count'))
            ->groupBy('sentiment')
            ->get()
            ->pluck('count', 'sentiment')
            ->toArray();

        // Calculate average ratings from Likert scores
        // Likert structure example: {"category1": 4, "category2": 5}
        $evaluations = Evaluation::where('event_id', $eventId)->get();
        $averageRating = 0;
        if ($evaluations->isNotEmpty()) {
            $totalScore = 0;
            $scoreCount = 0;
            foreach ($evaluations as $eval) {
                if (is_array($eval->section_scores)) {
                    foreach ($eval->section_scores as $category => $score) {
                        $totalScore += (int) $score;
                        $scoreCount++;
                    }
                }
            }
            $averageRating = $scoreCount > 0 ? round($totalScore / $scoreCount, 2) : 0;
        }

        // 3. Demographic breakdown of attendees by Program Code
        $demographics = User::role('Student')
            ->whereIn('id', function ($query) use ($eventId) {
                $query->select('student_id')
                    ->from('attendance_records')
                    ->where('event_id', $eventId)
                    ->whereNotNull('time_in');
            })
            ->select('program_code', DB::raw('count(*) as count'))
            ->groupBy('program_code')
            ->get();

        return response()->json([
            'success' => true,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_domain' => $event->event_domain,
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
            ],
            'attendance' => [
                'total_demographic' => $totalRegistered,
                'attended' => $attendedCount,
                'attendance_rate' => $attendanceRate,
                'global_unique_attended' => $attendedCount,
                'participant_scopes' => $scopeRollups,
            ],
            'evaluations' => [
                'total_submitted' => $totalEvaluations,
                'average_rating' => $averageRating,
                'sentiments' => [
                    'positive' => $sentiments['positive'] ?? 0,
                    'neutral' => $sentiments['neutral'] ?? 0,
                    'negative' => $sentiments['negative'] ?? 0,
                    'unprocessed' => $sentiments['unprocessed'] ?? 0,
                ]
            ],
            'demographics' => $demographics
        ]);
    }

    private function runtimeAnalyticsScopes(Event $event)
    {
        return $event->participationScopes
            ->filter(fn (EventParticipationScope $scope) => $scope->status === EventParticipationScope::STATUS_ACTIVE)
            ->filter(fn (EventParticipationScope $scope) => $scope->analytics_rollup_enabled)
            ->filter(fn (EventParticipationScope $scope) => !$this->participationScopes->runtimeGuard($scope))
            ->values();
    }

    private function audienceIdsForScope(EventParticipationScope $scope)
    {
        return $this->participationScopes
            ->audienceQuery($scope)
            ->pluck('users.id')
            ->unique()
            ->values();
    }

    /**
     * Calculate Gawad Parangal indicators for an organization.
     *
     * @param  string  $orgId
     * @return \Illuminate\Http\JsonResponse
     */
    public function gawadParangalMetrics(Request $request, $orgId)
    {
        $org = Organization::findOrFail($orgId);

        // Tenancy Check
        $user = $request->user();
        $scope = $this->adminScopes->forUser($user);
        if (!$scope || !$scope->canAccessOrganization($org)) {
            abort(403, 'Unauthorized.');
        }

        // 1. Average Attendance Rate across all approved events
        $events = Event::where('organization_id', $orgId)->where('status', 'completed')->get();
        
        $totalRegistered = User::role('Student')->where('organization_id', $orgId)->count();
        
        $avgAttendanceRate = 0;
        $evalAverageScore = 0;
        
        if ($events->isNotEmpty()) {
            $totalRates = 0;
            $totalEvalScore = 0;
            $evalCount = 0;

            foreach ($events as $event) {
                $attendedCount = AttendanceRecord::where('event_id', $event->id)
                    ->whereNotNull('time_in')
                    ->distinct('student_id')
                    ->count('student_id');

                $rate = $totalRegistered > 0 ? ($attendedCount / $totalRegistered) * 100 : 0;
                $totalRates += $rate;

                // Event evaluation average
                $evaluations = Evaluation::where('event_id', $event->id)->get();
                if ($evaluations->isNotEmpty()) {
                    $totalScore = 0;
                    $scoreCount = 0;
                    foreach ($evaluations as $eval) {
                        if (is_array($eval->section_scores)) {
                            foreach ($eval->section_scores as $score) {
                                $totalScore += (int) $score;
                                $scoreCount++;
                            }
                        }
                    }
                    if ($scoreCount > 0) {
                        $totalEvalScore += ($totalScore / $scoreCount);
                        $evalCount++;
                    }
                }
            }

            $avgAttendanceRate = round($totalRates / $events->count(), 2);
            $evalAverageScore = $evalCount > 0 ? round($totalEvalScore / $evalCount, 2) : 0;
        }

        // 2. Membership Active Ratio (Students who attended at least one event / total registered)
        $activeMembersCount = User::role('Student')
            ->where('organization_id', $orgId)
            ->whereIn('id', function ($query) use ($orgId) {
                $query->select('student_id')
                    ->from('attendance_records')
                    ->whereIn('event_id', function ($sub) use ($orgId) {
                        $sub->select('id')->from('events')->where('organization_id', $orgId);
                    })
                    ->whereNotNull('time_in');
            })
            ->count();

        $membershipActiveRatio = $totalRegistered > 0 ? round(($activeMembersCount / $totalRegistered) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'acronym' => $org->acronym,
            ],
            'metrics' => [
                'total_members' => $totalRegistered,
                'active_members' => $activeMembersCount,
                'membership_active_ratio' => $membershipActiveRatio,
                'average_event_attendance_rate' => $avgAttendanceRate,
                'average_evaluation_score' => $evalAverageScore,
            ]
        ]);
    }

    private function studentAudienceQueryForEvent(Event $event)
    {
        $query = User::role('Student');
        $eventOrg = $event->organization;

        if (!$eventOrg) {
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
}
