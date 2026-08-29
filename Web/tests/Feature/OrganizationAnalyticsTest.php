<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\OrganizationAnalyticsService;
use App\Services\ParticipationScopeService;
use App\Support\EventEvaluationForm;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_web_and_gawad_use_identical_weighted_organization_metrics_and_stable_keys(): void
    {
        $hierarchy = $this->createHierarchy('Analytics Society', 'ANL');
        $participant = $this->createHierarchy('Participant Society', 'PAR');
        $admin = $this->createAdmin('Society Admin', $hierarchy['organization']);
        $members = collect([
            $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Member One', '3rd_year'),
            $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Member Two', '3rd_year'),
            $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Member Three', '3rd_year'),
            $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Member Four', '3rd_year'),
        ]);
        $outsider = $this->createStudent($participant['organization'], $participant['program'], 'Participant Outsider', '3rd_year');
        $firstEvent = $this->createEvent($hierarchy['organization'], 'Small Event', '2026-08-01');
        $secondEvent = $this->createEvent($hierarchy['organization'], 'Large Event', '2026-08-08');
        $firstOrganizerScope = $this->organizerScope($firstEvent);
        $secondOrganizerScope = $this->organizerScope($secondEvent);
        $participantScope = EventParticipationScope::create([
            'event_id' => $firstEvent->id,
            'parent_scope_id' => $firstOrganizerScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participant['organization']->id,
            'college_id' => $participant['college']->id,
            'participant_name_snapshot' => 'PAR - Participant Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        $this->recordAttendance($firstEvent, $members[0], $firstOrganizerScope);
        $this->recordAttendance($firstEvent, $outsider, $participantScope);
        $this->recordAttendance($secondEvent, $members[0], $secondOrganizerScope);
        $this->recordAttendance($secondEvent, $members[1], $secondOrganizerScope);
        $this->recordAttendance($secondEvent, $members[2], $secondOrganizerScope);
        $this->recordEvaluation($firstEvent, $members[0], 1);
        $this->recordEvaluation($firstEvent, $outsider, 5);
        $this->recordEvaluation($secondEvent, $members[0], 5);
        $this->recordEvaluation($secondEvent, $members[1], 5);
        $this->recordEvaluation($secondEvent, $members[2], 5);

        $webResponse = $this->actingAs($admin)
            ->get(route('dashboard.analytics'))
            ->assertOk();
        $webMetrics = $webResponse->viewData('orgMetrics')[$hierarchy['organization']->id];

        $this->assertSame(4, $webMetrics['total_members']);
        $this->assertSame(3, $webMetrics['active_members']);
        $this->assertEquals(75.0, $webMetrics['active_member_ratio']);
        $this->assertEquals(50.0, $webMetrics['avg_attendance_rate']);
        $this->assertEquals(4.0, $webMetrics['avg_evaluation_score']);

        Sanctum::actingAs($admin);
        $apiResponse = $this->getJson($this->gawadPath($hierarchy['organization']))
            ->assertOk()
            ->assertExactJsonStructure([
                'success',
                'organization' => ['id', 'name', 'acronym'],
                'metrics' => [
                    'total_members',
                    'active_members',
                    'membership_active_ratio',
                    'average_event_attendance_rate',
                    'average_evaluation_score',
                ],
            ]);

        $this->assertEquals([
            'total_members' => $webMetrics['total_members'],
            'active_members' => $webMetrics['active_members'],
            'membership_active_ratio' => $webMetrics['active_member_ratio'],
            'average_event_attendance_rate' => $webMetrics['avg_attendance_rate'],
            'average_evaluation_score' => $webMetrics['avg_evaluation_score'],
        ], $apiResponse->json('metrics'));
    }

    public function test_organizer_scope_denominator_and_legacy_fallback_are_both_preserved(): void
    {
        $hierarchy = $this->createHierarchy('Scope Society', 'SCP');
        $other = $this->createHierarchy('Other Society', 'OTH');
        $primaryOne = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Primary One', '2nd_year');
        $primaryTwo = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Primary Two', '2nd_year');
        $secondaryMember = $this->createStudent($other['organization'], $other['program'], 'Secondary Member', '2nd_year');
        OrganizationMembership::create([
            'user_id' => $secondaryMember->id,
            'organization_id' => $hierarchy['organization']->id,
            'college_id' => $hierarchy['college']->id,
            'program_id' => $secondaryMember->program_id,
            'year_level' => $secondaryMember->year_level,
            'membership_type' => 'secondary_org',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $scopedEvent = $this->createEvent($hierarchy['organization'], 'Scoped Event', '2026-08-01');
        $legacyEvent = $this->createEvent($hierarchy['organization'], 'Legacy Event', '2026-08-02', createScope: false);
        $scope = $this->organizerScope($scopedEvent);

        $this->recordAttendance($scopedEvent, $primaryOne, $scope);
        $this->recordAttendance($scopedEvent, $primaryTwo, $scope);
        $this->recordAttendance($legacyEvent, $primaryOne);
        $this->recordAttendance($legacyEvent, $secondaryMember);

        $metrics = app(OrganizationAnalyticsService::class)->organizationMetrics($hierarchy['organization']);
        $breakdown = collect($metrics['event_breakdown'])->keyBy('title');

        $this->assertSame(3, $metrics['total_members']);
        $this->assertSame(3, $metrics['active_members']);
        $this->assertEquals(100.0, $metrics['active_member_ratio']);
        $this->assertEquals(66.67, $breakdown['Scoped Event']['attendance_rate']);
        $this->assertEquals(50.0, $breakdown['Legacy Event']['attendance_rate']);
        $this->assertEquals(58.34, $metrics['avg_attendance_rate']);
    }

    public function test_hr_domain_non_completed_and_hr_owned_events_are_excluded(): void
    {
        $hierarchy = $this->createHierarchy('Eligible Society', 'ELG');
        $studentOne = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Eligible One', '4th_year');
        $studentTwo = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Eligible Two', '4th_year');
        $eligible = $this->createEvent($hierarchy['organization'], 'Eligible Event', '2026-08-01');
        $approved = $this->createEvent($hierarchy['organization'], 'Approved Event', '2026-08-02', Event::STATUS_APPROVED);
        $hrDomain = $this->createEvent($hierarchy['organization'], 'HR Domain Event', '2026-08-03', domain: 'hr');

        $this->recordAttendance($eligible, $studentOne, $this->organizerScope($eligible));
        $this->recordEvaluation($eligible, $studentOne, 5);
        $this->recordAttendance($approved, $studentTwo, $this->organizerScope($approved));
        $this->recordEvaluation($approved, $studentTwo, 1);
        $this->recordAttendance($hrDomain, $studentTwo, $this->organizerScope($hrDomain));
        $this->recordEvaluation($hrDomain, $studentTwo, 1);

        $metrics = app(OrganizationAnalyticsService::class)->organizationMetrics($hierarchy['organization']);

        $this->assertSame(1, $metrics['total_events']);
        $this->assertEquals(50.0, $metrics['avg_attendance_rate']);
        $this->assertEquals(5.0, $metrics['avg_evaluation_score']);

        $hrHierarchy = $this->createHierarchy('Human Resources', 'HRS', 'hr_unit');
        $hrStudent = $this->createStudent($hrHierarchy['organization'], $hrHierarchy['program'], 'HR Student', '1st_year');
        $hrOwned = $this->createEvent($hrHierarchy['organization'], 'Legacy HR Owned Event', '2026-08-04');
        $this->recordAttendance($hrOwned, $hrStudent, $this->organizerScope($hrOwned));

        $hrMetrics = app(OrganizationAnalyticsService::class)->organizationMetrics($hrHierarchy['organization']);

        $this->assertSame(0, $hrMetrics['total_events']);
        $this->assertEquals(0, $hrMetrics['avg_attendance_rate']);
    }

    public function test_web_program_year_school_year_semester_and_event_type_filters_remain_effective(): void
    {
        $hierarchy = $this->createHierarchy('Filtered Society', 'FLT');
        $secondProgram = Program::create([
            'college_id' => $hierarchy['college']->id,
            'name' => 'BS Secondary Program',
            'code' => 'BSSP',
        ]);
        $hierarchy['organization']->programs()->attach($secondProgram->id);
        $matchingStudent = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Matching Student', '3rd_year');
        $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Other Year', '4th_year');
        $this->createStudent($hierarchy['organization'], $secondProgram, 'Other Program', '3rd_year');
        $admin = $this->createAdmin('Society Admin', $hierarchy['organization']);
        $matching = $this->createEvent($hierarchy['organization'], 'Matching Activity', '2026-08-15');
        $wrongType = $this->createEvent($hierarchy['organization'], 'Wrong Type', '2026-08-16', category: 'project');
        $wrongSemester = $this->createEvent($hierarchy['organization'], 'Wrong Semester', '2027-02-15');

        foreach ([$matching, $wrongType, $wrongSemester] as $event) {
            $this->recordAttendance($event, $matchingStudent, $this->organizerScope($event));
        }
        $this->recordEvaluation($matching, $matchingStudent, 4);
        $this->recordEvaluation($wrongType, $matchingStudent, 1);
        $this->recordEvaluation($wrongSemester, $matchingStudent, 1);

        $filters = [
            'program_id' => $hierarchy['program']->id,
            'year_level' => '3rd_year',
            'school_year' => '2026-2027',
            'semester' => '1st Semester',
            'event_type' => 'activity',
        ];
        $webResponse = $this->actingAs($admin)
            ->get(route('dashboard.analytics', $filters))
            ->assertOk()
            ->assertViewHas('selectedProgramId', $hierarchy['program']->id)
            ->assertViewHas('selectedYearLevel', '3rd_year');
        $webMetrics = $webResponse->viewData('orgMetrics')[$hierarchy['organization']->id];

        $this->assertSame(1, $webMetrics['total_events']);
        $this->assertSame(1, $webMetrics['total_members']);
        $this->assertEquals(100.0, $webMetrics['avg_attendance_rate']);
        $this->assertEquals(4.0, $webMetrics['avg_evaluation_score']);
        $this->assertCount(1, $webResponse->viewData('programComparison'));

        Sanctum::actingAs($admin);
        $apiMetrics = $this->getJson($this->gawadPath($hierarchy['organization'], $filters))
            ->assertOk()
            ->json('metrics');

        $this->assertEquals($webMetrics['avg_attendance_rate'], $apiMetrics['average_event_attendance_rate']);
        $this->assertEquals($webMetrics['avg_evaluation_score'], $apiMetrics['average_evaluation_score']);
        $this->assertSame($webMetrics['total_members'], $apiMetrics['total_members']);
    }

    public function test_existing_gawad_authorization_scope_is_preserved(): void
    {
        $hierarchy = $this->createHierarchy('Protected Society', 'PRT');
        $other = $this->createHierarchy('Other Protected Society', 'OPR');
        $otherAdmin = $this->createAdmin('Society Admin', $other['organization']);
        $unscopedUser = $this->createStudent($hierarchy['organization'], $hierarchy['program'], 'Unscoped Student', '1st_year');

        Sanctum::actingAs($otherAdmin);
        $this->getJson($this->gawadPath($hierarchy['organization']))->assertForbidden();

        Sanctum::actingAs($unscopedUser);
        $this->getJson($this->gawadPath($hierarchy['organization']))->assertForbidden();
    }

    /**
     * @return array{university: University, college: College, organization: Organization, program: Program}
     */
    private function createHierarchy(string $organizationName, string $acronym, string $type = 'society'): array
    {
        $sequence = ++$this->sequence;
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao'],
        );
        $college = College::create([
            'university_id' => $university->id,
            'name' => "Analytics College {$sequence}",
            'code' => "AC{$sequence}",
        ]);
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => $organizationName,
            'acronym' => $acronym,
            'type' => $type,
            'status' => 'active',
        ]);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => "BS Analytics {$sequence}",
            'code' => "BSA{$sequence}",
        ]);
        $organization->programs()->attach($program->id);

        return compact('university', 'college', 'organization', 'program');
    }

    private function createStudent(Organization $organization, Program $program, string $name, string $yearLevel): User
    {
        $sequence = ++$this->sequence;
        $student = User::create([
            'name' => $name,
            'email' => "analytics.student.{$sequence}@usm.edu.ph",
            'password' => null,
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'program_id' => $program->id,
            'student_id_number' => "26-{$sequence}",
            'qr_code_value' => "QR-ANALYTICS-{$sequence}",
            'program_code' => $program->code,
            'year_level' => $yearLevel,
            'user_type' => 'student',
        ]);
        $student->assignRole('Student');
        OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'program_id' => $program->id,
            'year_level' => $yearLevel,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        return $student;
    }

    private function createAdmin(string $role, Organization $organization): User
    {
        $sequence = ++$this->sequence;
        $admin = User::create([
            'name' => "{$role} Analytics Admin",
            'email' => "analytics.admin.{$sequence}@usm.edu.ph",
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);
        app(AdminAssignmentService::class)->createAssignment(
            user: $admin,
            roleName: $role,
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Analytics test admin',
            approvedBy: $admin,
            isPrimaryAdmin: false,
        );

        return $admin->fresh();
    }

    private function createEvent(
        Organization $organization,
        string $title,
        string $date,
        string $status = Event::STATUS_COMPLETED,
        string $domain = 'student_affairs',
        string $category = 'activity',
        bool $createScope = true,
    ): Event {
        $event = Event::create([
            'organization_id' => $organization->id,
            'title' => $title,
            'proposal_category' => $category,
            'status' => $status,
            'lifecycle_phase' => $status === Event::STATUS_COMPLETED ? 'completed' : 'scheduled',
            'event_domain' => $domain,
            'start_date' => $date,
            'end_date' => $date,
            'location_type' => 'on-campus',
        ]);
        EventEvaluationForm::createForEvent($event, null, [[
            'id' => 'quality',
            'label' => 'Quality',
            'description' => 'Overall event quality.',
        ]]);

        if ($createScope) {
            $this->organizerScope($event);
        }

        return $event->fresh(['organization', 'participationScopes']);
    }

    private function organizerScope(Event $event): EventParticipationScope
    {
        return app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));
    }

    private function recordAttendance(Event $event, User $student, ?EventParticipationScope $scope = null): void
    {
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope?->id,
            'student_id' => $student->id,
            'time_in' => $event->start_date->copy()->setTime(8, 0),
            'time_out' => $event->end_date->copy()->setTime(12, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => false,
            'valid' => true,
        ]);
    }

    private function recordEvaluation(Event $event, User $student, int $score): void
    {
        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'evaluation_form_version_id' => $event->evaluation_form_version_id,
            'section_scores' => ['quality' => $score],
            'open_comment' => null,
            'sentiment' => 'positive',
            'submitted_at' => $event->end_date->copy()->setTime(13, 0),
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function gawadPath(Organization $organization, array $filters = []): string
    {
        $path = "/api/v1/organizations/{$organization->id}/gawad-metrics";

        return $filters === [] ? $path : $path.'?'.http_build_query($filters);
    }
}
