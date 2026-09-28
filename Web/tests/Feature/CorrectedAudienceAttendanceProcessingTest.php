<?php

namespace Tests\Feature;

use App\Jobs\ResolveAttendanceJob;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventDaySession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PendingStudentLink;
use App\Models\Program;
use App\Models\RawScan;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\EventAnalyticsCache;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrectedAudienceAttendanceProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scanner_roster_hydration_filters_by_department_program_and_active_school_year_memberships(): void
    {
        $setup = $this->createScenario();
        $event = $setup['event'];
        $assignment = $setup['scannerAssignment'];
        $scannerUser = $setup['scannerUser'];

        // Hydration requires an activated scanner session token
        $mobileToken = $scannerUser->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'device-test-1',
            ])
            ->assertStatus(200);

        $sessionToken = $activation->json('token');

        Auth::forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$sessionToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=device-test-1")
            ->assertStatus(200);

        $roster = collect($response->json('roster'));

        // 1. Eligible student with active membership in the active School Year is included
        $this->assertTrue(
            $roster->contains('student_id', $setup['eligibleStudent']->id),
            'Eligible student must be present in the hydrated roster.'
        );

        // 2. Ineligible student from previous school year is filtered out
        $this->assertFalse(
            $roster->contains('student_id', $setup['previousYearStudent']->id),
            'Student with membership from previous school year must be filtered out.'
        );

        // 3. Ineligible student with inactive membership in current school year is filtered out
        $this->assertFalse(
            $roster->contains('student_id', $setup['inactiveMemberStudent']->id),
            'Student with inactive membership must be filtered out.'
        );

        // 4. Ineligible student from a different department is filtered out
        $this->assertFalse(
            $roster->contains('student_id', $setup['otherDeptStudent']->id),
            'Student from a different department must be filtered out.'
        );

        // Verify roster entry structure contains required scanner hydration fields
        $eligibleEntry = $roster->firstWhere('student_id', $setup['eligibleStudent']->id);
        $this->assertSame($setup['eligibleStudent']->qr_code_value, $eligibleEntry['qr_code_value']);
        $this->assertSame($setup['eligibleStudent']->name, $eligibleEntry['name']);
        $this->assertSame($setup['eligibleStudent']->student_id_number, $eligibleEntry['student_id_number']);
        $this->assertSame($setup['eligibleStudent']->program_code, $eligibleEntry['program_code']);
    }

    public function test_attendance_sync_validation_accepts_eligible_attendees_and_rejects_ineligible_attendees(): void
    {
        $setup = $this->createScenario();
        $event = $setup['event'];
        $assignment = $setup['scannerAssignment'];
        $scannerUser = $setup['scannerUser'];

        $mobileToken = $scannerUser->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'device-test-2',
            ])
            ->assertStatus(200);

        $sessionToken = $activation->json('token');
        Auth::forgetGuards();

        $scanTime = today()->setTime(8, 5, 0)->toISOString();

        $scans = [
            [
                'dedup_key' => 'dedup-eligible-1',
                'scan_type' => 'time_in',
                'scanned_at' => $scanTime,
                'device_id' => 'device-test-2',
                'manual_entry' => false,
                'qr_code_value' => $setup['eligibleStudent']->qr_code_value,
            ],
            [
                'dedup_key' => 'dedup-prev-year-2',
                'scan_type' => 'time_in',
                'scanned_at' => $scanTime,
                'device_id' => 'device-test-2',
                'manual_entry' => false,
                'qr_code_value' => $setup['previousYearStudent']->qr_code_value,
            ],
            [
                'dedup_key' => 'dedup-inactive-3',
                'scan_type' => 'time_in',
                'scanned_at' => $scanTime,
                'device_id' => 'device-test-2',
                'manual_entry' => false,
                'qr_code_value' => $setup['inactiveMemberStudent']->qr_code_value,
            ],
            [
                'dedup_key' => 'dedup-other-dept-4',
                'scan_type' => 'time_in',
                'scanned_at' => $scanTime,
                'device_id' => 'device-test-2',
                'manual_entry' => false,
                'qr_code_value' => $setup['otherDeptStudent']->qr_code_value,
            ],
        ];

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$sessionToken])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => $scans,
            ])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('ingested_count'));
        $this->assertSame(3, $response->json('rejected_count'));

        $rejectedScans = collect($response->json('rejected_scans'));
        $this->assertTrue($rejectedScans->contains('dedup_key', 'dedup-prev-year-2'));
        $this->assertTrue($rejectedScans->contains('dedup_key', 'dedup-inactive-3'));
        $this->assertTrue($rejectedScans->contains('dedup_key', 'dedup-other-dept-4'));

        // Verify only the eligible scan is stored in raw_scans
        $this->assertDatabaseHas('raw_scans', [
            'event_id' => $event->id,
            'student_id' => $setup['eligibleStudent']->id,
            'dedup_key' => 'dedup-eligible-1',
        ]);
        $this->assertDatabaseMissing('raw_scans', [
            'dedup_key' => 'dedup-prev-year-2',
        ]);
        $this->assertDatabaseMissing('raw_scans', [
            'dedup_key' => 'dedup-inactive-3',
        ]);
        $this->assertDatabaseMissing('raw_scans', [
            'dedup_key' => 'dedup-other-dept-4',
        ]);

        // Verify audit logs for rejected out-of-scope scans
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scanner-out-of-scope-scan-rejected',
            'target_id' => $event->id,
        ]);
    }

    public function test_resolve_attendance_job_resolves_eligible_attendee_and_discards_ineligible_attendee(): void
    {
        $setup = $this->createScenario();
        $event = $setup['event'];
        $scope = $setup['scope'];
        $eligible = $setup['eligibleStudent'];
        $ineligible = $setup['otherDeptStudent'];

        $day = $event->eventDays->first();
        $session = $day->sessions->first();

        // Create raw scans directly to simulate background processing
        RawScan::create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $session->id,
            'student_id' => $eligible->id,
            'qr_code_value' => $eligible->qr_code_value,
            'scan_type' => 'time_in',
            'scanned_at' => today()->setTime(8, 0, 0),
            'server_received_at' => now(),
            'device_id' => 'device-test-3',
            'manual_entry' => false,
            'dedup_key' => 'dedup-job-eligible-in',
        ]);

        RawScan::create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $session->id,
            'student_id' => $eligible->id,
            'qr_code_value' => $eligible->qr_code_value,
            'scan_type' => 'time_out',
            'scanned_at' => today()->setTime(12, 0, 0),
            'server_received_at' => now(),
            'device_id' => 'device-test-3',
            'manual_entry' => false,
            'dedup_key' => 'dedup-job-eligible-out',
        ]);

        RawScan::create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'event_day_session_id' => $session->id,
            'student_id' => $ineligible->id,
            'qr_code_value' => $ineligible->qr_code_value,
            'scan_type' => 'time_in',
            'scanned_at' => today()->setTime(8, 5, 0),
            'server_received_at' => now(),
            'device_id' => 'device-test-3',
            'manual_entry' => false,
            'dedup_key' => 'dedup-job-ineligible-in',
        ]);

        $job = new ResolveAttendanceJob($event->id, [$eligible->id, $ineligible->id], $scope->id);
        $job->handle(app(EventAnalyticsCache::class));

        // Eligible student receives attendance record
        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $eligible->id,
            'society_status' => 'present_on_time',
            'completion_status' => 'complete',
        ]);

        // Ineligible attendee must NOT receive attendance record
        $this->assertDatabaseMissing('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $ineligible->id,
        ]);

        // Raw scans for both must have processed_at marked
        $this->assertNotNull(RawScan::where('dedup_key', 'dedup-job-eligible-in')->first()->processed_at);
        $this->assertNotNull(RawScan::where('dedup_key', 'dedup-job-ineligible-in')->first()->processed_at);
    }

    public function test_pending_qr_resolution_validates_student_credentials_and_corrected_hierarchy(): void
    {
        $setup = $this->createScenario();
        $event = $setup['event'];
        $scope = $setup['scope'];
        $admin = $setup['admin'];
        $eligible = $setup['eligibleStudent'];
        $otherDept = $setup['otherDeptStudent'];

        // Token for admin
        $adminToken = $admin->createToken('admin-web', ['*'])->plainTextToken;

        $pendingLink = PendingStudentLink::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'organization_id' => $setup['org']->id,
            'qr_code_value' => 'QR-UNKNOWN-999',
            'status' => 'pending',
        ]);

        // 1. Resolving to student from different department fails
        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->postJson("/api/v1/pending-student-links/{$pendingLink->id}/resolve", [
                'student_id' => $otherDept->id,
                'notes' => 'Attempting to resolve other dept student',
            ])
            ->assertStatus(403);

        $pendingLink->refresh();
        $this->assertSame('pending', $pendingLink->status);

        // 2. Resolving to student with mismatched hierarchy (mismatched department to college) fails
        $corruptedStudent = User::factory()->create([
            'name' => 'Corrupted Hierarchy Student',
            'email' => 'corrupted-'.uniqid().'@usm.edu.ph',
            'college_id' => $setup['college']->id,
            'department_id' => $setup['otherCollegeDept']->id, // belongs to other college!
            'student_id_number' => '2026-99999',
            'qr_code_value' => 'QR-CORRUPTED',
        ]);
        $corruptedStudent->assignRole('Student');

        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->postJson("/api/v1/pending-student-links/{$pendingLink->id}/resolve", [
                'student_id' => $corruptedStudent->id,
                'notes' => 'Attempting to resolve corrupted hierarchy student',
            ])
            ->assertStatus(403);

        $pendingLink->refresh();
        $this->assertSame('pending', $pendingLink->status);

        // 3. Resolving to eligible student matching hierarchy and active school year succeeds
        $this->withHeaders(['Authorization' => 'Bearer '.$adminToken])
            ->postJson("/api/v1/pending-student-links/{$pendingLink->id}/resolve", [
                'student_id' => $eligible->id,
                'notes' => 'Valid manual resolution',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $pendingLink->refresh();
        $this->assertSame('resolved', $pendingLink->status);
        $this->assertSame($eligible->id, $pendingLink->resolved_student_id);
    }

    public function test_offline_sync_idempotency_and_duplicate_handling(): void
    {
        $setup = $this->createScenario();
        $event = $setup['event'];
        $assignment = $setup['scannerAssignment'];
        $scannerUser = $setup['scannerUser'];

        $mobileToken = $scannerUser->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'device-test-idempotency',
            ])
            ->assertStatus(200);

        $sessionToken = $activation->json('token');
        Auth::forgetGuards();

        $scanTime = today()->setTime(8, 10, 0)->toISOString();

        // Unique dedup_key computed using idempotency formula
        $dedupKey = md5($event->id.$setup['eligibleStudent']->id.'time_in'.$scanTime);

        $batch = [
            'event_id' => $event->id,
            'scans' => [
                [
                    'dedup_key' => $dedupKey,
                    'scan_type' => 'time_in',
                    'scanned_at' => $scanTime,
                    'device_id' => 'device-test-idempotency',
                    'manual_entry' => false,
                    'qr_code_value' => $setup['eligibleStudent']->qr_code_value,
                ],
            ],
        ];

        // First sync: Ingests 1 scan
        $firstResponse = $this->withHeaders(['Authorization' => 'Bearer '.$sessionToken])
            ->postJson('/api/v1/attendance/sync', $batch)
            ->assertStatus(200);

        $this->assertSame(1, $firstResponse->json('ingested_count'));
        $this->assertSame(0, $firstResponse->json('duplicate_count'));
        $this->assertSame(1, RawScan::where('dedup_key', $dedupKey)->count());

        Auth::forgetGuards();

        // Second sync: Identical batch resent due to offline sync retry / connection recovery
        $secondResponse = $this->withHeaders(['Authorization' => 'Bearer '.$sessionToken])
            ->postJson('/api/v1/attendance/sync', $batch)
            ->assertStatus(200);

        // Idempotent: 0 newly ingested, 1 duplicate, exactly 1 row in database
        $this->assertSame(0, $secondResponse->json('ingested_count') ?? 0);
        $this->assertSame(1, RawScan::where('dedup_key', $dedupKey)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function createScenario(): array
    {
        $university = University::create(['name' => 'University of Southern Mindanao', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $university->id, 'name' => 'College of Engineering and Information Technology', 'code' => 'CEIT']);
        $otherCollege = College::create(['university_id' => $university->id, 'name' => 'College of Agriculture', 'code' => 'CA']);

        $dept = Department::create(['college_id' => $college->id, 'name' => 'Department of Information Technology', 'code' => 'DIT']);
        $otherDept = Department::create(['college_id' => $college->id, 'name' => 'Department of Civil Engineering', 'code' => 'DCE']);
        $otherCollegeDept = Department::create(['college_id' => $otherCollege->id, 'name' => 'Department of Agronomy', 'code' => 'DAG']);

        $program = Program::create(['department_id' => $dept->id, 'name' => 'BS Information Systems', 'code' => 'BSIS']);
        $otherProgram = Program::create(['department_id' => $otherDept->id, 'name' => 'BS Civil Engineering', 'code' => 'BSCE']);

        $org = Organization::create([
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'name' => 'Philippine Society of Information Technology Students',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);
        $org->programs()->attach($program->id);

        $osa = User::factory()->create([
            'name' => 'OSA Officer',
            'email' => 'osa-'.uniqid().'@usm.edu.ph',
        ]);
        $osa->assignRole('Super Admin (OSA)');

        $activeSchoolYear = SchoolYear::factory()->active($osa)->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
        ]);

        $closedSchoolYear = SchoolYear::factory()->create([
            'label' => '2025-2026',
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
            'status' => SchoolYear::STATUS_CLOSED,
            'closed_by' => $osa->id,
            'closed_at' => now(),
        ]);

        $admin = User::factory()->create([
            'name' => 'PSITS Admin',
            'email' => 'admin-'.uniqid().'@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
        ]);
        $admin->assignRole('Society Admin');
        $admin->givePermissionTo(['assign-scanners-own', 'scan-qr-codes']);

        app(AdminAssignmentService::class)->createAssignment(
            user: $admin,
            roleName: 'Society Admin',
            organization: $org,
            college: $college,
            academicYear: $activeSchoolYear->label,
            termStart: $activeSchoolYear->starts_on?->toDateString(),
            termEnd: $activeSchoolYear->ends_on?->toDateString(),
            positionTitle: 'President',
            approvedBy: $admin,
            isPrimaryAdmin: true,
            department: $dept,
        );

        $scannerUser = User::factory()->create([
            'name' => 'Scanner Student',
            'email' => 'scanner-'.uniqid().'@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'student_id_number' => '2026-10001',
            'qr_code_value' => 'QR-SCANNER-10001',
        ]);
        $scannerUser->assignRole('Student');
        $scannerUser->givePermissionTo('scan-qr-codes');

        OrganizationMembership::create([
            'user_id' => $scannerUser->id,
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'school_year_id' => $activeSchoolYear->id,
            'academic_year' => $activeSchoolYear->label,
            'status' => 'active',
        ]);

        // 1. Eligible student: DIT, BSIS, active membership in PSITS for 2026-2027
        $eligibleStudent = User::factory()->create([
            'name' => 'Eligible Student',
            'email' => 'eligible-'.uniqid().'@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'program_code' => 'BSIS',
            'student_id_number' => '2026-20001',
            'qr_code_value' => 'QR-ELIGIBLE-20001',
        ]);
        $eligibleStudent->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $eligibleStudent->id,
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'school_year_id' => $activeSchoolYear->id,
            'academic_year' => $activeSchoolYear->label,
            'status' => 'active',
        ]);

        // 2. Previous year student: membership only in closed 2025-2026
        $previousYearStudent = User::factory()->create([
            'name' => 'Previous Year Student',
            'email' => 'prev-'.uniqid().'@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'program_code' => 'BSIS',
            'student_id_number' => '2025-20002',
            'qr_code_value' => 'QR-PREV-20002',
        ]);
        $previousYearStudent->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $previousYearStudent->id,
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'school_year_id' => $closedSchoolYear->id,
            'academic_year' => $closedSchoolYear->label,
            'status' => 'active',
        ]);

        // 3. Inactive member student: status = inactive in active 2026-2027
        $inactiveMemberStudent = User::factory()->create([
            'name' => 'Inactive Member Student',
            'email' => 'inactive-'.uniqid().'@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'program_code' => 'BSIS',
            'student_id_number' => '2026-20003',
            'qr_code_value' => 'QR-INACTIVE-20003',
        ]);
        $inactiveMemberStudent->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $inactiveMemberStudent->id,
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'school_year_id' => $activeSchoolYear->id,
            'academic_year' => $activeSchoolYear->label,
            'status' => 'inactive',
        ]);

        // 4. Other department student: belongs to DCE
        $otherDeptStudent = User::factory()->create([
            'name' => 'Other Dept Student',
            'email' => 'otherdept-'.uniqid().'@usm.edu.ph',
            'organization_id' => null,
            'college_id' => $college->id,
            'department_id' => $otherDept->id,
            'program_id' => $otherProgram->id,
            'program_code' => 'BSCE',
            'student_id_number' => '2026-20004',
            'qr_code_value' => 'QR-OTHERDEPT-20004',
        ]);
        $otherDeptStudent->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'school_year_id' => $activeSchoolYear->id,
            'title' => 'PSITS General Assembly 2026',
            'status' => 'approved',
            'lifecycle_phase' => 'active',
            'start_date' => today(),
            'end_date' => today(),
            'society_late_threshold_min' => 15,
            'general_competition_threshold_min' => 30,
            'left_early_buffer_min' => 15,
        ]);

        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);

        EventDaySession::create([
            'event_day_id' => $day->id,
            'session_number' => 1,
            'name' => 'Morning Session',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $participationScopes = app(ParticipationScopeService::class);
        $scope = $participationScopes->ensureOrganizerScope($event->fresh(['organization']));

        $scannerAssignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $scannerUser,
            assignedBy: $admin,
            label: 'Door 1 Scanner',
            participationScope: $scope
        );

        return compact(
            'university',
            'college',
            'otherCollege',
            'dept',
            'otherDept',
            'otherCollegeDept',
            'program',
            'otherProgram',
            'org',
            'osa',
            'activeSchoolYear',
            'closedSchoolYear',
            'admin',
            'scannerUser',
            'eligibleStudent',
            'previousYearStudent',
            'inactiveMemberStudent',
            'otherDeptStudent',
            'event',
            'scope',
            'scannerAssignment'
        );
    }
}
