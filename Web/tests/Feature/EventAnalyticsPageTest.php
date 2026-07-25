<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PendingStudentLink;
use App\Models\Program;
use App\Models\RawScan;
use App\Models\ScannerSession;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\EventAnalyticsService;
use App\Services\ParticipationScopeService;
use App\Support\EventEvaluationForm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    public function test_authorized_admin_can_view_event_analytics_page(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $path = route('dashboard.events.analytics', $fixture['event']);

        $this->actingAs($fixture['admin'])
            ->get(route('dashboard.events.analytics', $fixture['event']))
            ->assertOk()
            ->assertSee('Event Analytics')
            ->assertSee($fixture['event']->title)
            ->assertSee('Participant Scopes')
            ->assertSee('Scanner &amp; Sync', false)
            ->assertSee('Activity relevance')
            ->assertSee('Participant Drilldown')
            ->assertSee('Year Level Mix')
            ->assertSee('All year levels')
            ->assertSee('method="GET"', false)
            ->assertSee('action="'.$path.'"', false)
            ->assertSee('wire:submit.prevent="applyFilters"', false)
            ->assertSee('name="tab"', false)
            ->assertSee('value="attendance"', false)
            ->assertSee('name="q"', false)
            ->assertSee('name="scope_id"', false)
            ->assertSee('name="day_id"', false)
            ->assertSee('name="status"', false)
            ->assertSee('name="validation"', false)
            ->assertSee('name="year_level"', false)
            ->assertSee('name="per_page"', false);
    }

    public function test_students_and_cross_tenant_admins_are_denied_event_analytics(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $student = $fixture['participantMissing'];

        $this->actingAs($student)
            ->get(route('dashboard.events.analytics', $fixture['event']))
            ->assertStatus(403);

        $other = $this->createHierarchy('College of Agriculture', 'COA', 'Aggies Society', 'AGS');
        $otherAdmin = $this->createAdmin('Society Admin', $other['org']);

        $this->actingAs($otherAdmin)
            ->get(route('dashboard.events.analytics', $fixture['event']))
            ->assertStatus(403);
    }

    public function test_event_analytics_json_and_api_routes_share_expanded_metrics(): void
    {
        $fixture = $this->createAnalyticsFixture();

        $this->actingAs($fixture['admin'])
            ->getJson(route('dashboard.events.analytics.data', $fixture['event']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('attendance.total_demographic', 3)
            ->assertJsonPath('attendance.attended', 2)
            ->assertJsonPath('attendance.valid_unique_attended', 1)
            ->assertJsonPath('daily_attendance.0.status_counts.late', 1)
            ->assertJsonPath('daily_attendance.1.left_early_records', 1)
            ->assertJsonPath('evaluations.sentiments.positive', 1)
            ->assertJsonPath('evaluations.sentiments.negative', 1)
            ->assertJsonPath('scanner_sync.pending_links.pending', 1)
            ->assertJsonPath('scanner_sync.raw_scans.manual_entries', 1)
            ->assertJsonPath('year_levels.0.label', '2nd Year')
            ->assertJsonPath('year_levels.0.count', 1)
            ->assertJsonPath('evaluations.category_averages.0.label', 'Activity relevance');

        Sanctum::actingAs($fixture['admin']);

        $this->getJson("/api/v1/events/{$fixture['event']->id}/analytics")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('attendance.total_demographic', 3)
            ->assertJsonPath('attendance.global_unique_attended', 2)
            ->assertJsonStructure([
                'event',
                'attendance' => ['participant_scopes'],
                'evaluations' => ['sentiments', 'category_averages'],
                'scanner_sync',
                'demographics',
                'year_levels',
            ]);
    }

    public function test_participant_drilldown_can_filter_by_year_level(): void
    {
        $fixture = $this->createAnalyticsFixture();

        $rows = app(EventAnalyticsService::class)
            ->participantRows($fixture['event'], ['year_level' => '2nd_year'], 25)
            ->getCollection();

        $this->assertSame(
            ['Participant Attendee', 'Participant Missing'],
            $rows->pluck('name')->values()->all()
        );
    }

    public function test_event_analytics_participant_filters_work_with_plain_get_reload(): void
    {
        $fixture = $this->createAnalyticsFixture();

        $this->actingAs($fixture['admin'])
            ->get(route('dashboard.events.analytics', [
                'event' => $fixture['event'],
                'tab' => 'attendance',
                'year_level' => '2nd_year',
                'status' => 'late',
                'validation' => 'invalid',
                'q' => 'Participant',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertSee('method="GET"', false)
            ->assertSee('name="tab"', false)
            ->assertSee('value="attendance"', false)
            ->assertSee('name="year_level"', false)
            ->assertSee('value="2nd_year" selected', false)
            ->assertSee('name="status"', false)
            ->assertSee('value="late" selected', false)
            ->assertSee('name="validation"', false)
            ->assertSee('value="invalid" selected', false)
            ->assertSee('name="q"', false)
            ->assertSee('value="Participant"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('value="10" selected', false)
            ->assertSee('href="'.route('dashboard.events.analytics', ['event' => $fixture['event'], 'tab' => 'attendance']).'"', false)
            ->assertSee('Participant Attendee')
            ->assertDontSee('Participant Missing');
    }

    public function test_livewire_participant_drilldown_filters_apply_without_full_page_reload(): void
    {
        $fixture = $this->createAnalyticsFixture();

        Livewire::actingAs($fixture['admin'])
            ->test(\App\Livewire\Dashboard\EventAnalyticsParticipantFilters::class, [
                'eventId' => $fixture['event']->id,
            ])
            ->set('yearLevel', '2nd_year')
            ->set('status', 'late')
            ->set('validation', 'invalid')
            ->set('search', 'Participant')
            ->call('applyFilters')
            ->assertSet('yearLevel', '2nd_year')
            ->assertSet('status', 'late')
            ->assertSet('validation', 'invalid')
            ->assertSee('Participant Attendee')
            ->assertDontSee('Participant Missing');
    }

    public function test_livewire_participant_drilldown_paginates_without_full_page_reload(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $org = $fixture['participantScope']->organization()->firstOrFail();
        $program = $org->programs()->firstOrFail();

        foreach (range(1, 28) as $index) {
            $this->createStudent(
                $org,
                $program,
                sprintf('Paged Participant %02d', $index),
                sprintf('24-9%03d', $index),
                '2nd_year'
            );
        }

        Livewire::actingAs($fixture['admin'])
            ->test(\App\Livewire\Dashboard\EventAnalyticsParticipantFilters::class, [
                'eventId' => $fixture['event']->id,
            ])
            ->set('perPage', 10)
            ->assertSee('Paged Participant 01')
            ->assertDontSee('Paged Participant 19')
            ->call('nextPage')
            ->assertSee('Paged Participant 10')
            ->assertDontSee('Paged Participant 01');
    }

    public function test_participation_scope_denominators_daily_eilo_evaluations_and_sync_counts_are_correct(): void
    {
        $fixture = $this->createAnalyticsFixture();

        $response = $this->actingAs($fixture['admin'])
            ->getJson(route('dashboard.events.analytics.data', $fixture['event']))
            ->assertOk();

        $participantScope = collect($response->json('participant_scopes'))
            ->firstWhere('id', $fixture['participantScope']->id);

        $this->assertSame(2, $participantScope['expected_audience']);
        $this->assertSame(1, $participantScope['attended']);
        $this->assertEquals(50.0, $participantScope['attendance_rate']);
        $this->assertSame(1, $participantScope['evaluation_submissions']);

        $this->assertSame(2, $response->json('daily_attendance.0.attended'));
        $this->assertSame(1, $response->json('daily_attendance.0.status_counts.late'));
        $this->assertSame(1, $response->json('daily_attendance.0.status_counts.absent'));
        $this->assertSame(1, $response->json('daily_attendance.1.attended'));
        $this->assertSame(2, $response->json('daily_attendance.1.status_counts.absent'));

        $category = collect($response->json('evaluations.category_averages'))
            ->firstWhere('key', 'activity_relevance');
        $this->assertSame(2, $category['responses']);
        $this->assertSame(3.5, $category['average']);
        $this->assertSame(1, $category['distribution'][2]);
        $this->assertSame(1, $category['distribution'][5]);

        $this->assertSame(1, $response->json('scanner_sync.assignments.active'));
        $this->assertSame(1, $response->json('scanner_sync.sessions.active'));
        $this->assertSame(2, $response->json('scanner_sync.raw_scans.total'));
        $this->assertSame(1, $response->json('scanner_sync.raw_scans.unresolved'));
    }

    public function test_events_and_organization_analytics_link_to_full_event_analytics_page(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $path = route('dashboard.events.analytics', $fixture['event']);

        $this->actingAs($fixture['admin'])
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('href="'.$path.'"', false);

        $this->actingAs($fixture['admin'])
            ->get(route('dashboard.analytics'))
            ->assertOk()
            ->assertSee($path, false)
            ->assertSee('Open full analytics');
    }

    private function createAnalyticsFixture(): array
    {
        $organizer = $this->createHierarchy();
        $participant = $this->createHierarchy('College of Business', 'COB', 'Junior Managers Society', 'JMS');
        $admin = $this->createAdmin('Society Admin', $organizer['org']);

        $organizerStudent = $this->createStudent($organizer['org'], $organizer['program'], 'Organizer Attendee', '24-0001', '3rd_year');
        $participantAttended = $this->createStudent($participant['org'], $participant['program'], 'Participant Attendee', '24-0002', '2nd_year');
        $participantMissing = $this->createStudent($participant['org'], $participant['program'], 'Participant Missing', '24-0003', '2nd_year');

        $event = Event::create([
            'organization_id' => $organizer['org']->id,
            'title' => 'Analytics Evidence Event',
            'proposal_category' => 'activity',
            'status' => 'completed',
            'lifecycle_phase' => 'completed',
            'event_domain' => 'student_affairs',
            'start_date' => Carbon::today()->subDays(2),
            'end_date' => Carbon::today()->subDay(),
            'location_type' => 'on-campus',
            'location_details' => 'USM Auditorium',
            'target_demographics' => ['target_participants' => 'Organizer and invited society students'],
            'hardcopy_submitted' => true,
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);
        $form = EventEvaluationForm::createForEvent(
            $event,
            $admin,
            [
                [
                    'id' => 'activity_relevance',
                    'label' => 'Activity relevance',
                    'description' => 'Was the activity relevant to the intended participants?',
                ],
                [
                    'id' => 'facilitator_quality',
                    'label' => 'Facilitator quality',
                    'description' => 'Did the facilitator support the learning or activity flow?',
                ],
                [
                    'id' => 'venue_readiness',
                    'label' => 'Venue readiness',
                    'description' => 'Was the venue prepared for the activity?',
                ],
            ],
            'Evaluation - Analytics Evidence Event'
        );

        $dayOne = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today()->subDays(2),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $dayTwo = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 2,
            'date' => Carbon::today()->subDay(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $organizerScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));
        $participantScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $organizerScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participant['org']->id,
            'college_id' => $participant['college']->id,
            'participant_name_snapshot' => 'JMS - Junior Managers Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'hardcopy_submitted' => true,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $organizerScope->id,
            'event_day_id' => $dayOne->id,
            'student_id' => $organizerStudent->id,
            'time_in' => Carbon::parse($dayOne->date->toDateString().' 07:55:00'),
            'time_out' => Carbon::parse($dayOne->date->toDateString().' 12:05:00'),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $participantScope->id,
            'event_day_id' => $dayOne->id,
            'student_id' => $participantAttended->id,
            'time_in' => Carbon::parse($dayOne->date->toDateString().' 08:22:00'),
            'society_status' => 'late',
            'competition_status' => 'present_on_time',
            'valid' => false,
        ]);
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $organizerScope->id,
            'event_day_id' => $dayTwo->id,
            'student_id' => $organizerStudent->id,
            'time_in' => Carbon::parse($dayTwo->date->toDateString().' 08:00:00'),
            'time_out' => Carbon::parse($dayTwo->date->toDateString().' 10:40:00'),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => true,
            'valid' => true,
        ]);

        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $organizerStudent->id,
            'evaluation_form_version_id' => $form->id,
            'section_scores' => [
                'activity_relevance' => 5,
                'facilitator_quality' => 4,
                'venue_readiness' => 4,
            ],
            'open_comment' => 'Clear program flow and useful activity.',
            'sentiment' => 'positive',
            'sentiment_score' => 0.92,
            'submitted_at' => now()->subHours(5),
        ]);
        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $participantAttended->id,
            'evaluation_form_version_id' => $form->id,
            'section_scores' => [
                'activity_relevance' => 2,
                'facilitator_quality' => 3,
                'venue_readiness' => 2,
            ],
            'open_comment' => 'The venue was crowded and the second activity started late.',
            'sentiment' => 'negative',
            'sentiment_score' => 0.78,
            'submitted_at' => now()->subHours(4),
        ]);

        $assignment = EventScannerAssignment::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $participantScope->id,
            'audience_scope_type' => 'society',
            'scanner_user_id' => $admin->id,
            'assigned_by_user_id' => $admin->id,
            'label' => 'JMS Gate',
            'status' => EventScannerAssignment::STATUS_ACTIVE,
            'max_devices' => 1,
            'last_activated_at' => now()->subHours(6),
        ]);
        $session = ScannerSession::create([
            'event_id' => $event->id,
            'assignment_id' => $assignment->id,
            'event_participation_scope_id' => $participantScope->id,
            'scanner_user_id' => $admin->id,
            'issued_by' => $admin->id,
            'label' => 'JMS Android',
            'device_id' => 'device-analytics-1',
            'status' => ScannerSession::STATUS_ACTIVE,
            'issued_at' => now()->subHours(7),
            'activated_at' => now()->subHours(6),
            'last_seen_at' => now()->subMinutes(10),
        ]);
        $rawScan = RawScan::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $participantScope->id,
            'event_day_id' => $dayOne->id,
            'scanner_session_id' => $session->id,
            'scanner_user_id' => $admin->id,
            'captured_by_organization_id' => $participant['org']->id,
            'captured_by_admin_scope' => 'participant',
            'student_id' => $participantAttended->id,
            'qr_code_value' => 'QR-24-0002',
            'scan_type' => 'time_in',
            'scanned_at' => now()->subHours(5),
            'server_received_at' => now()->subHours(5)->addSeconds(2),
            'processed_at' => now()->subHours(5)->addSeconds(5),
            'device_id' => 'device-analytics-1',
            'manual_entry' => false,
            'dedup_key' => 'analytics-scan-1',
        ]);
        RawScan::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $participantScope->id,
            'event_day_id' => $dayOne->id,
            'scanner_session_id' => $session->id,
            'scanner_user_id' => $admin->id,
            'captured_by_organization_id' => $participant['org']->id,
            'captured_by_admin_scope' => 'participant',
            'student_id' => null,
            'qr_code_value' => 'QR-UNKNOWN',
            'scan_type' => 'time_in',
            'scanned_at' => now()->subHours(4),
            'server_received_at' => now()->subHours(4)->addSeconds(2),
            'device_id' => 'device-analytics-1',
            'manual_entry' => true,
            'dedup_key' => 'analytics-scan-2',
            'rejection_reason' => 'student_not_found',
        ]);
        PendingStudentLink::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $participantScope->id,
            'organization_id' => $participant['org']->id,
            'raw_scan_id' => $rawScan->id,
            'qr_code_value' => 'QR-UNKNOWN',
            'status' => 'pending',
        ]);

        return compact(
            'event',
            'admin',
            'organizerScope',
            'participantScope',
            'organizerStudent',
            'participantAttended',
            'participantMissing'
        );
    }

    private function createHierarchy(
        string $collegeName = 'College of Engineering',
        string $collegeCode = 'CEIT',
        string $orgName = 'PSITS',
        string $orgAcronym = 'PSITS'
    ): array {
        $uni = University::firstOrCreate(['domain' => 'usm.edu.ph'], ['name' => 'University of Southern Mindanao']);
        $college = College::create(['university_id' => $uni->id, 'name' => $collegeName, 'code' => $collegeCode]);
        $org = Organization::create(['college_id' => $college->id, 'name' => $orgName, 'acronym' => $orgAcronym, 'type' => 'society']);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'BS '.$orgAcronym,
            'code' => 'BS'.$orgAcronym,
        ]);
        $org->programs()->attach($program->id);

        return compact('uni', 'college', 'org', 'program');
    }

    private function createStudent(Organization $org, Program $program, string $name, string $studentIdNumber, string $yearLevel = '3rd_year'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@usm.edu.ph',
            'password' => null,
            'organization_id' => $org->id,
            'college_id' => $org->college_id,
            'program_id' => $program->id,
            'student_id_number' => $studentIdNumber,
            'qr_code_value' => 'QR-'.$studentIdNumber,
            'program_code' => $program->code,
            'year_level' => $yearLevel,
            'user_type' => 'student',
        ]);
        $user->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'college_id' => $org->college_id,
            'program_id' => $program->id,
            'year_level' => $yearLevel,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'test',
            'verified_at' => now(),
        ]);

        return $user;
    }

    private function createAdmin(string $role, ?Organization $org = null): User
    {
        $user = User::create([
            'name' => $role.' User',
            'email' => strtolower(str_replace([' ', '(', ')'], '', $role)).uniqid().'@usm.edu.ph',
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);

        $assignmentOrganization = $role === 'Super Admin (OSA)' ? null : $org;
        $assignmentCollege = $assignmentOrganization?->college;

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $role,
            organization: $assignmentOrganization,
            college: $assignmentCollege,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Test admin',
            approvedBy: $user,
            isPrimaryAdmin: false
        );

        return $user->fresh();
    }

    private function seedRolesAndPermissions(): void
    {
        $permissions = [
            'manage-organizations',
            'approve-proposals',
            'view-all-analytics',
            'create-proposals',
            'assign-scanners-own',
            'view-own-analytics',
            'register-profile',
            'submit-evaluations',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'Super Admin (OSA)', 'guard_name' => 'web'])
            ->syncPermissions(['manage-organizations', 'approve-proposals', 'view-all-analytics']);
        Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'view-own-analytics']);
        Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'])
            ->syncPermissions(['register-profile', 'submit-evaluations']);
        Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Academic Unit Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Office Event Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'HR Admin', 'guard_name' => 'web']);
    }
}
