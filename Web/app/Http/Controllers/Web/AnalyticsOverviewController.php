<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsOverviewController extends Controller
{
    /**
     * Render the Organization Performance Analytics Overview page.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Resolve Filters and Scopes (RBAC)
        $selectedCollegeId = $request->input('college_id');
        $selectedOrgId = $request->input('organization_id');
        $selectedProgramId = $request->input('program_id');
        $selectedSchoolYear = $request->input('school_year');
        $selectedSemester = $request->input('semester');
        $selectedEventType = $request->input('event_type');

        // LSG Admin: Lock to their college
        if ($user->hasRole('LSG Admin')) {
            $userOrg = $user->organization;
            if ($userOrg && $userOrg->college_id) {
                $selectedCollegeId = $userOrg->college_id;
            }
        }
        // Society Admin: Lock to their organization
        elseif (!$user->hasRole('Super Admin (OSA)')) {
            $selectedOrgId = $user->organization_id;
            $userOrg = $user->organization;
            if ($userOrg && $userOrg->college_id) {
                $selectedCollegeId = $userOrg->college_id;
            }
        }

        // 2. Fetch Cascading Filter Dropdown Options
        // Colleges
        $collegesQuery = College::orderBy('name');
        if ($selectedCollegeId) {
            $collegesQuery->where('id', $selectedCollegeId);
        }
        $filterColleges = $collegesQuery->get();

        // Organizations
        $orgsQuery = Organization::orderBy('name');
        if ($selectedCollegeId) {
            $orgsQuery->where('college_id', $selectedCollegeId);
        }
        if ($selectedOrgId) {
            $orgsQuery->where('id', $selectedOrgId);
        }
        $filterOrganizations = $orgsQuery->get();

        // Programs
        $programsQuery = Program::orderBy('name');
        if ($selectedOrgId) {
            $programsQuery->whereHas('organizations', function ($q) use ($selectedOrgId) {
                $q->where('organization_id', $selectedOrgId);
            });
        } elseif ($selectedCollegeId) {
            $programsQuery->where('college_id', $selectedCollegeId);
        }
        $filterPrograms = $programsQuery->get();

        // Distinct School Years (dynamically derived from events)
        $schoolYears = Event::where('status', 'completed')
            ->orderBy('start_date', 'desc')
            ->pluck('start_date')
            ->map(function ($date) {
                if (!$date) return null;
                $year = (int)$date->format('Y');
                $month = (int)$date->format('m');
                return $month < 6 ? ($year - 1) . '-' . $year : $year . '-' . ($year + 1);
            })
            ->filter()
            ->unique()
            ->values();

        // 3. Build Scoped Organization Query for Analytics Cards
        $orgsQueryForCards = Organization::query()->with('college');
        if ($selectedCollegeId) {
            $orgsQueryForCards->where('college_id', $selectedCollegeId);
        }
        if ($selectedOrgId) {
            $orgsQueryForCards->where('id', $selectedOrgId);
        }
        $organizations = $orgsQueryForCards->orderBy('name')->get();

        // 4. Compute Metrics per Organization
        $orgMetrics = [];
        $globalAttendanceSum = 0;
        $globalEvalSum = 0;
        $globalEvalCount = 0;

        foreach ($organizations as $org) {
            $metrics = $this->computeOrgMetrics($org, $selectedProgramId, $selectedSchoolYear, $selectedSemester, $selectedEventType);
            $orgMetrics[$org->id] = $metrics;

            $globalAttendanceSum += $metrics['avg_attendance_rate'];
            if ($metrics['avg_evaluation_score'] > 0) {
                $globalEvalSum += $metrics['avg_evaluation_score'];
                $globalEvalCount++;
            }
        }

        $orgCount = $organizations->count();
        $summary = [
            'total_orgs'          => $orgCount,
            'avg_attendance_rate' => $orgCount > 0 ? round($globalAttendanceSum / $orgCount, 2) : 0,
            'avg_eval_score'      => $globalEvalCount > 0 ? round($globalEvalSum / $globalEvalCount, 2) : 0,
        ];

        // Sort Leaderboard: composite score (attendance 50% + evaluation 50%)
        $ranked = $organizations->map(function ($org) use ($orgMetrics) {
            $m = $orgMetrics[$org->id];
            $composite = ($m['avg_attendance_rate'] * 0.5)
                + ($m['avg_evaluation_score'] > 0 ? ($m['avg_evaluation_score'] / 5.0 * 100 * 0.5) : 0);
            return array_merge(['org' => $org], $m, ['composite_score' => round($composite, 2)]);
        })->sortByDesc('composite_score')->values();

        // 5. Compute Program Comparison Leaderboard (Tab 2)
        // Select programs scoped to User's College if LSG Admin, or filtered college
        $progCompareQuery = Program::with('college', 'organizations');
        if ($selectedCollegeId) {
            $progCompareQuery->where('college_id', $selectedCollegeId);
        }
        if ($selectedProgramId) {
            $progCompareQuery->where('id', $selectedProgramId);
        }
        $programsForComparison = $progCompareQuery->orderBy('name')->get();
        $programComparison = [];

        foreach ($programsForComparison as $program) {
            $programComparison[] = $this->computeProgramMetrics($program, $selectedSchoolYear, $selectedSemester, $selectedEventType);
        }

        // Sort programs by composite score
        usort($programComparison, function ($a, $b) {
            return $b['composite_score'] <=> $a['composite_score'];
        });

        // Group filters mapping for JS
        $orgsByCollege = [];
        $allOrgs = Organization::orderBy('name')->get();
        foreach ($allOrgs as $org) {
            $orgsByCollege[$org->college_id ?? 'university_wide'][] = [
                'id' => $org->id,
                'name' => $org->name,
                'acronym' => $org->acronym
            ];
        }

        $programsByCollege = [];
        $allPrograms = Program::orderBy('name')->get();
        foreach ($allPrograms as $prog) {
            $programsByCollege[$prog->college_id][] = [
                'id' => $prog->id,
                'name' => $prog->name,
                'code' => $prog->code
            ];
        }

        // Link program-to-organizations map
        $programsByOrg = [];
        foreach ($allOrgs as $org) {
            $linkedProgs = $org->programs()->get();
            foreach ($linkedProgs as $lp) {
                $programsByOrg[$org->id][] = [
                    'id' => $lp->id,
                    'name' => $lp->name,
                    'code' => $lp->code
                ];
            }
        }

        return view('dashboard.analytics.index', compact(
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
            'selectedSchoolYear',
            'selectedSemester',
            'selectedEventType'
        ));
    }

    /**
     * Compute aggregated performance metrics for a single organization.
     */
    private function computeOrgMetrics(Organization $org, $programId = null, $schoolYear = null, $semester = null, $eventType = null): array
    {
        // Fetch completed events hosted by organization
        $eventsQuery = Event::where('organization_id', $org->id)
            ->where('status', 'completed');

        // Apply filters on events
        $this->applyEventFilters($eventsQuery, $schoolYear, $semester, $eventType);
        $completedEvents = $eventsQuery->get();

        // Get total organization students (optionally filtered by program)
        $studentsQuery = User::role('Student')->where('organization_id', $org->id);
        if ($programId) {
            $studentsQuery->where('program_id', $programId);
        }
        $totalMembers = $studentsQuery->count();

        $totalEvents = $completedEvents->count();
        $avgAttendanceRate = 0;
        $avgValidAttendanceRate = 0;
        $avgEvalScore = 0;
        $totalEvalResponses = 0;
        $totalPossibleEvals = 0;
        $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $eventBreakdown = [];

        // Dynamic status counters
        $totalLateCount = 0;
        $totalAbsentCount = 0;
        $totalLeftEarlyCount = 0;

        if ($completedEvents->isNotEmpty()) {
            $attendanceRateSum = 0;
            $validRateSum = 0;
            $evalScoreSum = 0;
            $evalScoreCount = 0;

            foreach ($completedEvents as $event) {
                // Attendance count for this event scoped by student filter
                $attendedCount = AttendanceRecord::where('event_id', $event->id)
                    ->whereNotNull('time_in')
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->distinct('student_id')
                    ->count('student_id');

                $eventAttendanceRate = $totalMembers > 0
                    ? round(($attendedCount / $totalMembers) * 100, 2)
                    : 0;
                $attendanceRateSum += $eventAttendanceRate;

                // Valid attendance records (passed evaluation gate)
                $validCount = AttendanceRecord::where('event_id', $event->id)
                    ->where('valid', true)
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->distinct('student_id')
                    ->count('student_id');

                $eventValidRate = $totalMembers > 0
                    ? round(($validCount / $totalMembers) * 100, 2)
                    : 0;
                $validRateSum += $eventValidRate;

                // Late, Absent, Left Early aggregates
                $lateCount = AttendanceRecord::where('event_id', $event->id)
                    ->whereIn('society_status', ['late', 'late_cutoff'])
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->count();
                $totalLateCount += $lateCount;

                $absentCount = AttendanceRecord::where('event_id', $event->id)
                    ->where('society_status', 'absent')
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->count();
                $totalAbsentCount += $absentCount;

                $leftEarlyCount = AttendanceRecord::where('event_id', $event->id)
                    ->where('left_early', true)
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->count();
                $totalLeftEarlyCount += $leftEarlyCount;

                // Evaluations for this event
                $evaluations = Evaluation::where('event_id', $event->id)
                    ->whereIn('student_id', function ($q) use ($org, $programId) {
                        $q->select('id')->from('users')->where('organization_id', $org->id);
                        if ($programId) {
                            $q->where('program_id', $programId);
                        }
                    })
                    ->get();

                $eventEvalScore = 0;
                $eventEvalCount = $evaluations->count();
                $totalEvalResponses += $eventEvalCount;
                $totalPossibleEvals += $totalMembers;

                if ($evaluations->isNotEmpty()) {
                    $totalScore = 0;
                    $scoreItems = 0;
                    foreach ($evaluations as $eval) {
                        if (is_array($eval->section_scores)) {
                            foreach ($eval->section_scores as $score) {
                                $totalScore += (int) $score;
                                $scoreItems++;
                            }
                        }
                        // Aggregate sentiments
                        if ($eval->sentiment && isset($sentimentCounts[$eval->sentiment])) {
                            $sentimentCounts[$eval->sentiment]++;
                        }
                    }
                    if ($scoreItems > 0) {
                        $eventEvalScore = round($totalScore / $scoreItems, 2);
                        $evalScoreSum += $eventEvalScore;
                        $evalScoreCount++;
                    }
                }

                $eventBreakdown[] = [
                    'id'              => $event->id,
                    'title'           => $event->title,
                    'start_date'      => $event->start_date?->format('M d, Y'),
                    'attended'        => $attendedCount,
                    'attendance_rate' => $eventAttendanceRate,
                    'eval_score'      => $eventEvalScore,
                    'eval_responses'  => $eventEvalCount,
                ];
            }

            $avgAttendanceRate = round($attendanceRateSum / $totalEvents, 2);
            $avgValidAttendanceRate = round($validRateSum / $totalEvents, 2);
            $avgEvalScore = $evalScoreCount > 0 ? round($evalScoreSum / $evalScoreCount, 2) : 0;
        }

        // Active members: attended at least one event
        $activeMembersCount = User::role('Student')
            ->where('organization_id', $org->id)
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->whereIn('id', function ($query) use ($org, $programId, $completedEvents) {
                $query->select('student_id')
                    ->from('attendance_records')
                    ->whereIn('event_id', $completedEvents->pluck('id'))
                    ->whereNotNull('time_in');
            })
            ->count();

        $activeMemberRatio = $totalMembers > 0
            ? round(($activeMembersCount / $totalMembers) * 100, 2)
            : 0;

        $evalResponseRate = $totalPossibleEvals > 0
            ? round(($totalEvalResponses / $totalPossibleEvals) * 100, 2)
            : 0;

        $totalSentiments = array_sum($sentimentCounts);
        $positivePct = $totalSentiments > 0
            ? round(($sentimentCounts['positive'] / $totalSentiments) * 100, 1)
            : 0;
        $negativePct = $totalSentiments > 0
            ? round(($sentimentCounts['negative'] / $totalSentiments) * 100, 1)
            : 0;

        // Populate Program-level sub-breakdowns (to show details inside organization card)
        $linkedProgramsMetrics = [];
        if (!$programId) {
            $linkedPrograms = $org->programs()->get();
            foreach ($linkedPrograms as $prog) {
                $progMetrics = $this->computeOrgMetrics($org, $prog->id, $schoolYear, $semester, $eventType);
                $linkedProgramsMetrics[] = [
                    'program' => $prog,
                    'avg_attendance_rate' => $progMetrics['avg_attendance_rate'],
                    'avg_evaluation_score' => $progMetrics['avg_evaluation_score']
                ];
            }
        }

        return [
            'total_events'           => $totalEvents,
            'total_members'          => $totalMembers,
            'active_members'         => $activeMembersCount,
            'active_member_ratio'    => $activeMemberRatio,
            'avg_attendance_rate'    => $avgAttendanceRate,
            'avg_valid_attendance'   => $avgValidAttendanceRate,
            'avg_evaluation_score'   => $avgEvalScore,
            'eval_response_rate'     => $evalResponseRate,
            'sentiment_positive'     => $positivePct,
            'sentiment_negative'     => $negativePct,
            'total_late'             => $totalLateCount,
            'total_absent'           => $totalAbsentCount,
            'total_left_early'       => $totalLeftEarlyCount,
            'event_breakdown'        => $eventBreakdown,
            'linked_programs_metrics' => $linkedProgramsMetrics
        ];
    }

    /**
     * Compute aggregated metrics for an academic program (across all matched events).
     */
    private function computeProgramMetrics(Program $program, $schoolYear = null, $semester = null, $eventType = null): array
    {
        // Get all completed events
        $eventsQuery = Event::where('status', 'completed');
        $this->applyEventFilters($eventsQuery, $schoolYear, $semester, $eventType);
        $completedEvents = $eventsQuery->get();
        $completedEventIds = $completedEvents->pluck('id');

        // Total registered students in program
        $studentsQuery = User::role('Student')->where('program_id', $program->id);
        $totalMembers = $studentsQuery->count();
        $studentIds = $studentsQuery->pluck('id');

        $totalEvents = 0;
        $avgAttendanceRate = 0;
        $avgValidAttendance = 0;
        $avgEvalScore = 0;
        $evalResponseRate = 0;
        $sentimentPositive = 0;
        $sentimentNegative = 0;
        $totalLate = 0;
        $totalAbsent = 0;
        $totalLeftEarly = 0;

        if ($completedEvents->isNotEmpty() && $totalMembers > 0) {
            $attendanceRateSum = 0;
            $validRateSum = 0;
            $evalScoreSum = 0;
            $evalScoreCount = 0;
            $totalPossibleEvals = 0;
            $totalEvalResponses = 0;
            $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

            foreach ($completedEvents as $event) {
                $hasStudents = AttendanceRecord::where('event_id', $event->id)
                    ->whereIn('student_id', $studentIds)
                    ->exists();

                if (!$hasStudents) continue;
                $totalEvents++;

                // Attended count
                $attendedCount = AttendanceRecord::where('event_id', $event->id)
                    ->whereNotNull('time_in')
                    ->whereIn('student_id', $studentIds)
                    ->distinct('student_id')
                    ->count('student_id');

                $attendanceRateSum += ($attendedCount / $totalMembers) * 100;

                // Valid attendance
                $validCount = AttendanceRecord::where('event_id', $event->id)
                    ->where('valid', true)
                    ->whereIn('student_id', $studentIds)
                    ->distinct('student_id')
                    ->count('student_id');
                $validRateSum += ($validCount / $totalMembers) * 100;

                // Late / Absent / Left Early
                $totalLate += AttendanceRecord::where('event_id', $event->id)
                    ->whereIn('society_status', ['late', 'late_cutoff'])
                    ->whereIn('student_id', $studentIds)
                    ->count();

                $totalAbsent += AttendanceRecord::where('event_id', $event->id)
                    ->where('society_status', 'absent')
                    ->whereIn('student_id', $studentIds)
                    ->count();

                $totalLeftEarly += AttendanceRecord::where('event_id', $event->id)
                    ->where('left_early', true)
                    ->whereIn('student_id', $studentIds)
                    ->count();

                // Evaluations
                $evaluations = Evaluation::where('event_id', $event->id)
                    ->whereIn('student_id', $studentIds)
                    ->get();
                $totalEvalResponses += $evaluations->count();
                $totalPossibleEvals += $totalMembers;

                if ($evaluations->isNotEmpty()) {
                    $totalScore = 0;
                    $scoreItems = 0;
                    foreach ($evaluations as $eval) {
                        if (is_array($eval->section_scores)) {
                            foreach ($eval->section_scores as $score) {
                                $totalScore += (int) $score;
                                $scoreItems++;
                            }
                        }
                        if ($eval->sentiment && isset($sentimentCounts[$eval->sentiment])) {
                            $sentimentCounts[$eval->sentiment]++;
                        }
                    }
                    if ($scoreItems > 0) {
                        $evalScoreSum += ($totalScore / $scoreItems);
                        $evalScoreCount++;
                    }
                }
            }

            if ($totalEvents > 0) {
                $avgAttendanceRate = round($attendanceRateSum / $totalEvents, 2);
                $avgValidAttendance = round($validRateSum / $totalEvents, 2);
                $avgEvalScore = $evalScoreCount > 0 ? round($evalScoreSum / $evalScoreCount, 2) : 0;
                $evalResponseRate = $totalPossibleEvals > 0 ? round(($totalEvalResponses / $totalPossibleEvals) * 100, 2) : 0;

                $totalSentiments = array_sum($sentimentCounts);
                $sentimentPositive = $totalSentiments > 0 ? round(($sentimentCounts['positive'] / $totalSentiments) * 100, 1) : 0;
                $sentimentNegative = $totalSentiments > 0 ? round(($sentimentCounts['negative'] / $totalSentiments) * 100, 1) : 0;
            }
        }

        // Composite score for program leaderboard
        $compositeScore = ($avgAttendanceRate * 0.5)
            + ($avgEvalScore > 0 ? ($avgEvalScore / 5.0 * 100 * 0.5) : 0);

        return [
            'program'              => $program,
            'total_events'         => $totalEvents,
            'total_members'        => $totalMembers,
            'avg_attendance_rate'  => $avgAttendanceRate,
            'avg_valid_attendance' => $avgValidAttendance,
            'avg_evaluation_score' => $avgEvalScore,
            'eval_response_rate'   => $evalResponseRate,
            'sentiment_positive'   => $sentimentPositive,
            'sentiment_negative'   => $sentimentNegative,
            'total_late'           => $totalLate,
            'total_absent'         => $totalAbsent,
            'total_left_early'     => $totalLeftEarly,
            'composite_score'      => round($compositeScore, 2)
        ];
    }

    /**
     * Helper to apply school year, semester, and event type queries to event builder.
     */
    private function applyEventFilters($query, $schoolYear = null, $semester = null, $eventType = null): void
    {
        if ($schoolYear) {
            [$startYear, $endYear] = explode('-', $schoolYear);
            $yearStart = "{$startYear}-06-01";
            $yearEnd = "{$endYear}-05-31";

            if ($semester) {
                if ($semester === '1st Semester') {
                    $query->whereBetween('start_date', ["{$startYear}-06-01", "{$startYear}-10-31"]);
                } elseif ($semester === '2nd Semester') {
                    $query->whereBetween('start_date', ["{$startYear}-11-01", "{$endYear}-03-31"]);
                } elseif ($semester === 'Summer') {
                    $query->whereBetween('start_date', ["{$endYear}-04-01", "{$endYear}-05-31"]);
                }
            } else {
                $query->whereBetween('start_date', [$yearStart, $yearEnd]);
            }
        } elseif ($semester) {
            if ($semester === '1st Semester') {
                $query->whereRaw('EXTRACT(MONTH FROM start_date) BETWEEN 6 AND 10');
            } elseif ($semester === '2nd Semester') {
                $query->whereRaw('EXTRACT(MONTH FROM start_date) IN (11, 12, 1, 2, 3)');
            } elseif ($semester === 'Summer') {
                $query->whereRaw('EXTRACT(MONTH FROM start_date) BETWEEN 4 AND 5');
            }
        }

        if ($eventType) {
            $query->where('proposal_category', $eventType);
        }
    }
}
