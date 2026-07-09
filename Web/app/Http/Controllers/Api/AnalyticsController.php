<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Models\AttendanceRecord;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Services\AdminScopeService;
use App\Services\EventAnalyticsService;
use App\Support\EventTenantScope;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $adminScopes,
        private readonly EventAnalyticsService $eventAnalytics
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

        return response()->json([
            'success' => true,
            ...$this->eventAnalytics->summary($event),
        ]);
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
