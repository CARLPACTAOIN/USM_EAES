<?php

namespace Tests\Feature;

use App\Jobs\ResolveAttendanceJob;
use App\Models\AdminAssignment;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventDaySession;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\RawScan;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\EventAnalyticsCache;
use App\Services\EventProposalDraftService;
use App\Services\EventSessionScheduleService;
use App\Services\ScannerSessionService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventDaySessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00', 'Asia/Manila'));
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_schedule_service_defaults_to_one_session_and_rejects_overlaps(): void
    {
        $service = app(EventSessionScheduleService::class);

        $normalized = $service->normalizeEventDays([[
            'date' => Carbon::today()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'sessions' => [],
        ]]);

        $this->assertCount(1, $normalized[0]['sessions']);
        $this->assertSame('08:00', $normalized[0]['sessions'][0]['start_time']);
        $this->assertSame('17:00', $normalized[0]['sessions'][0]['end_time']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must not overlap');

        $service->normalizeEventDays([[
            'date' => Carbon::today()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'sessions' => [
                ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '12:00', 'required_for_attendance' => true],
                ['name' => 'Overlap', 'start_time' => '11:30', 'end_time' => '15:00', 'required_for_attendance' => true],
            ],
        ]]);
    }

    public function test_attendance_sync_rejects_between_session_time_in_and_persists_resolved_session(): void
    {
        Queue::fake();

        [$event, $scope, $day, $student, $scanner] = $this->attendanceContext();
        [$morning] = $this->createSessions($day);
        $token = $this->scannerTokenForEvent($event, $scanner);

        $this->withToken($token)->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [[
                'dedup_key' => 'between-session-time-in',
                'scan_type' => 'time_in',
                'scanned_at' => Carbon::parse($day->date->toDateString().' 11:30:00')->toDateTimeString(),
                'device_id' => 'session-device',
                'manual_entry' => false,
                'qr_code_value' => $student->qr_code_value,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('ingested_count', null)
            ->assertJsonPath('rejected_count', 1)
            ->assertJsonPath('rejected_scans.0.reason', 'session_time_in_window_closed');

        $this->assertDatabaseMissing('raw_scans', [
            'dedup_key' => 'between-session-time-in',
        ]);

        $this->withToken($token)->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [[
                'dedup_key' => 'morning-session-time-in',
                'scan_type' => 'time_in',
                'scanned_at' => Carbon::parse($day->date->toDateString().' 08:05:00')->toDateTimeString(),
                'device_id' => 'session-device',
                'manual_entry' => false,
                'qr_code_value' => $student->qr_code_value,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('ingested_count', 1)
            ->assertJsonPath('rejected_count', 0);

        $this->assertDatabaseHas('raw_scans', [
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $morning->id,
            'dedup_key' => 'morning-session-time-in',
        ]);
    }

    public function test_proposal_draft_service_persists_configured_day_sessions(): void
    {
        [$existingEvent, , , , $actor] = $this->attendanceContext();

        $event = app(EventProposalDraftService::class)->create($actor, [
            'organization_id' => $existingEvent->organization_id,
            'parent_event_id' => null,
            'title' => 'Session Proposal',
            'proposal_category' => 'activity',
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
            'location_type' => 'off-campus',
            'venue_id' => null,
            'location_details' => 'Training Center',
            'venue_confirmation_office' => null,
            'venue_confirmation_date' => null,
            'venue_confirmation_reference_number' => null,
            'target_demographics' => ['All members'],
            'budget_allocations' => [['item' => 'Materials', 'amount' => 1000]],
            'resolution_number' => null,
            'evaluation_questions' => null,
            'event_days' => [[
                'date' => Carbon::today()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '17:00',
                'sessions' => [
                    ['name' => 'Morning Workshop', 'start_time' => '08:00', 'end_time' => '11:00', 'required_for_attendance' => true],
                    ['name' => 'Afternoon Workshop', 'start_time' => '13:00', 'end_time' => '17:00', 'required_for_attendance' => true],
                ],
            ]],
        ], null);

        $day = $event->eventDays()->with('sessions')->firstOrFail();

        $this->assertSame(['Morning Workshop', 'Afternoon Workshop'], $day->sessions
            ->sortBy('session_number')
            ->pluck('name')
            ->values()
            ->all());
        $this->assertDatabaseHas('event_day_sessions', [
            'event_day_id' => $day->id,
            'session_number' => 2,
            'name' => 'Afternoon Workshop',
            'start_time' => '13:00',
            'end_time' => '17:00',
        ]);
    }

    public function test_resolution_job_creates_canonical_attendance_per_session(): void
    {
        [$event, $scope, $day, $student] = $this->attendanceContext();
        [$morning, $afternoon] = $this->createSessions($day);

        RawScan::create($this->rawScanCandidate($event, $scope, 'morning-in', $student, 'time_in', '08:00:00'));
        RawScan::create($this->rawScanCandidate($event, $scope, 'morning-out', $student, 'time_out', '11:00:00'));
        RawScan::create($this->rawScanCandidate($event, $scope, 'afternoon-in', $student, 'time_in', '13:00:00'));
        RawScan::create($this->rawScanCandidate($event, $scope, 'afternoon-out', $student, 'time_out', '17:00:00'));

        (new ResolveAttendanceJob($event->id, [$student->id], $scope->id))
            ->handle(app(EventAnalyticsCache::class));

        $this->assertSame(2, AttendanceRecord::query()
            ->where('event_id', $event->id)
            ->where('student_id', $student->id)
            ->count());

        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $morning->id,
            'student_id' => $student->id,
            'completion_status' => 'complete',
            'left_early' => false,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $afternoon->id,
            'student_id' => $student->id,
            'completion_status' => 'complete',
            'left_early' => false,
        ]);

        $this->assertSame(4, RawScan::query()
            ->where('event_id', $event->id)
            ->whereNotNull('event_day_session_id')
            ->count());
    }

    /**
     * @return array{Event, EventParticipationScope, EventDay, User, User}
     */
    private function attendanceContext(): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);
        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Session Testing',
            'code' => 'CST',
        ]);
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Session Testing Society',
            'acronym' => 'STS',
            'type' => 'society',
        ]);
        $event = Event::create([
            'organization_id' => $organization->id,
            'title' => 'Whole Day Session Event',
            'status' => Event::STATUS_APPROVED,
            'lifecycle_phase' => 'active',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'event_late_cutoff_min' => 15,
            'left_early_buffer_min' => 15,
        ]);
        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'participant_type' => 'organization',
            'organization_id' => $organization->id,
            'participant_name_snapshot' => $organization->acronym,
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);
        $student = User::create([
            'name' => 'Session Student',
            'email' => 'session-student-'.Str::uuid().'@usm.edu.ph',
            'organization_id' => $organization->id,
            'college_id' => $college->id,
            'student_id_number' => '2026-'.random_int(1000, 9999),
            'qr_code_value' => 'QR-'.Str::uuid(),
        ]);
        $student->assignRole('Student');
        $scanner = User::create([
            'name' => 'Session Scanner',
            'email' => 'session-scanner-'.Str::uuid().'@usm.edu.ph',
        ]);
        $this->assignAdminScope($scanner, $organization);
        $scanner->assignRole('Scanner');

        return [$event, $scope, $day, $student, $scanner];
    }

    /**
     * @return array{EventDaySession, EventDaySession}
     */
    private function createSessions(EventDay $day): array
    {
        return [
            EventDaySession::create([
                'event_day_id' => $day->id,
                'session_number' => 1,
                'name' => 'Morning Session',
                'start_time' => '08:00:00',
                'end_time' => '11:00:00',
                'required_for_attendance' => true,
            ]),
            EventDaySession::create([
                'event_day_id' => $day->id,
                'session_number' => 2,
                'name' => 'Afternoon Session',
                'start_time' => '13:00:00',
                'end_time' => '17:00:00',
                'required_for_attendance' => true,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawScanCandidate(Event $event, EventParticipationScope $scope, string $dedupKey, User $student, string $scanType, string $time): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => null,
            'event_day_session_id' => null,
            'scanner_session_id' => null,
            'scanner_user_id' => null,
            'captured_by_organization_id' => $scope->organization_id,
            'captured_by_admin_scope' => $scope->participation_role,
            'student_id' => $student->id,
            'qr_code_value' => $student->qr_code_value,
            'scan_type' => $scanType,
            'scanned_at' => Carbon::parse($event->start_date->toDateString().' '.$time),
            'server_received_at' => now(),
            'device_id' => 'session-device',
            'manual_entry' => false,
            'dedup_key' => $dedupKey,
        ];
    }

    private function assignAdminScope(User $scanner, Organization $organization): void
    {
        app(AdminAssignmentService::class)->createAssignment(
            user: $scanner,
            roleName: 'Society Admin',
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Session test scanner',
            approvedBy: $scanner,
            isPrimaryAdmin: ! AdminAssignment::query()
                ->where('organization_id', $organization->id)
                ->where('role_name', 'Society Admin')
                ->where('status', AdminAssignment::STATUS_ACTIVE)
                ->exists(),
        );
    }

    private function scannerTokenForEvent(Event $event, User $scanner): string
    {
        [, $token] = app(ScannerSessionService::class)->createSession(
            event: $event,
            scannerUser: $scanner,
            issuedBy: $scanner,
            revokeExisting: false,
        );

        return $token;
    }
}
