<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeEventSentimentsJob;
use App\Jobs\ResolveAttendanceJob;
use App\Models\AdminAssignment;
use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\PendingStudentLink;
use App\Models\Program;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\RawScan;
use App\Models\ScannerSession;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\Contracts\AiServiceInterface;
use App\Services\EventAnalyticsCache;
use App\Services\EventLifecycleService;
use App\Services\GeminiService;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use App\Support\EvaluationQuestions;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsmEaesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Test the entire USM EAES backend pipeline.
     */
    public function test_eaes_backend_pipeline()
    {
        $this->withoutExceptionHandling();
        // 1. Seed University, College, Organization
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Engineering and Information Technology',
            'code' => 'CEIT',
        ]);
        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Department of Information and Communication Technology',
            'code' => 'ICT',
            'status' => 'active',
        ]);

        $org = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'Philippine Society of Information Technology Students',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);

        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        // 2. Create Student & Scanner Admin Users
        $student = User::create([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '23-1234',
            'qr_code_value' => 'QR_JUAN_123',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $societyAdmin = User::create([
            'name' => 'Admin Officer',
            'email' => 'admin.officer@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);
        $this->assignAdminScope($societyAdmin, 'Society Admin', $org, $college);
        $societyAdmin->assignRole('Scanner');

        // 3. Create Event with Event Days (PPA)
        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'IT Programming Seminar',
            'status' => 'approved', // must be approved for scanning
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'society_late_threshold_min' => 15,
            'general_competition_threshold_min' => 30,
            'left_early_buffer_min' => 15,
        ]);

        $day1 = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        // 4. Authenticate Scanner via API
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.officer@usm.edu.ph',
            'password' => 'password123',
            'device_name' => 'Test Scanner Device',
        ]);

        $loginResponse->assertStatus(200);
        $token = $this->scannerTokenForEvent($event, $societyAdmin);

        // 5. Test Hydration API (Roster pre-caching)
        $hydrateResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v1/events/{$event->id}/hydrate");

        $hydrateResponse->assertStatus(200);
        $hydrateResponse->assertJsonFragment([
            'qr_code_value' => 'QR_JUAN_123',
            'name' => 'Juan Dela Cruz',
        ]);

        // 6. Test Bulk Sync API (Ingest raw scans)
        $dedup1 = 'dedup_key_in_1';
        $dedup2 = 'dedup_key_out_2';

        $syncResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [
                // Juan Dela Cruz times in at event start (On-Time for Society and Competition)
                [
                    'dedup_key' => $dedup1,
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ],
                // Juan Dela Cruz times out at event end (Completed and not Left Early)
                [
                    'dedup_key' => $dedup2,
                    'scan_type' => 'time_out',
                    'scanned_at' => Carbon::today()->setTime(12, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ],
            ],
        ]);

        $syncResponse->assertStatus(200);
        $syncResponse->assertJsonFragment([
            'ingested_count' => 2,
        ]);

        // 7. Verify duplicates are ignored
        $syncResponseDup = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/attendance/sync', [
            'event_id' => $event->id,
            'scans' => [
                [
                    'dedup_key' => $dedup1, // duplicate dedup key
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_abc',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_JUAN_123',
                ],
            ],
        ]);

        $syncResponseDup->assertStatus(200);
        $syncResponseDup->assertJsonFragment([
            'message' => 'All scans in batch were duplicates, skipped.',
        ]);

        // Assert 2 raw scans exist
        $this->assertEquals(2, RawScan::count());

        // 8. Run Resolution Job & Verify Attendance Records
        $job = new ResolveAttendanceJob($event->id, [$student->id]);
        $job->handle(app(EventAnalyticsCache::class));

        $this->assertEquals(1, AttendanceRecord::count());
        $record = AttendanceRecord::first();

        $this->assertEquals('present_on_time', $record->society_status);
        $this->assertEquals('present_on_time', $record->competition_status);
        $this->assertFalse($record->left_early);
        $this->assertFalse($record->valid); // Locked under the evaluation gate!

        // 9. Submit Evaluation (unlocks gate)
        Auth::forgetGuards();
        $studentToken = $student->createToken('Student Phone')->plainTextToken;

        $evalResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$studentToken,
        ])->postJson('/api/v1/evaluations', [
            'event_id' => $event->id,
            'section_scores' => [
                'attainment_of_objectives' => 5,
                'speaker_mastery' => 5,
                'venue_comfort' => 4,
            ],
            'open_comment' => 'The program was highly educational!',
        ]);

        $evalResponse->assertStatus(201);

        // Assert the gate is unlocked (Attendance record status valid is updated to true)
        $record->refresh();
        $this->assertTrue($record->valid);
    }

    public function test_unapproved_events_lock_scanner_endpoints()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Draft Orientation',
            'status' => 'submitted',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $token = $this->scannerTokenForEvent($event, $scanner);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'locked-event-scan',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_locked',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_LOCKED',
                ]],
            ])
            ->assertStatus(423)
            ->assertJsonFragment(['event_status' => 'submitted']);
    }

    public function test_usg_admin_can_create_university_event_in_own_scope(): void
    {
        Storage::fake('local');

        $usgOrg = Organization::create([
            'college_id' => null,
            'name' => 'University Student Government',
            'acronym' => 'USG',
            'type' => 'usg',
        ]);

        $societyOrg = Organization::create([
            'college_id' => null,
            'name' => 'Campus Society',
            'acronym' => 'CS',
            'type' => 'society',
        ]);

        $student = User::create([
            'name' => 'University Student',
            'email' => 'university.student@usm.edu.ph',
            'organization_id' => $societyOrg->id,
            'student_id_number' => '24-3001',
            'qr_code_value' => 'QR_UNIVERSITY_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $usgAdmin = User::create([
            'name' => 'USG Officer',
            'email' => 'usg.officer@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);
        $this->assignAdminScope($usgAdmin, 'USG Admin', $usgOrg, null);
        $this->createWorkflowTemplateForOrganizationType('usg', [
            ['usg_proponent', 'USG Proponent', 'proponent', 'organization'],
            ['usg_head', 'USG Head', 'organization_head', 'organization'],
            ['usg_osa', 'OSA Review', 'osa', 'university'],
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'usg.officer@usm.edu.ph',
            'password' => 'password123',
            'device_name' => 'USG Workstation',
        ])->assertStatus(200);

        $token = $loginResponse->json('token');
        $venue = Venue::create([
            'name' => 'University Auditorium',
            'campus_location' => 'Main Campus',
            'managing_office' => 'Physical Plant and Facilities Office',
            'is_active' => true,
        ]);

        $createResponse = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->post('/api/v1/events', [
                'title' => 'University General Assembly',
                'proposal_category' => 'activity',
                'canonical_packet' => [
                    'activity_role' => 'organizer',
                    'proponent_name' => 'USG Secretary',
                    'proponent_position' => 'Secretary',
                    'proponent_organization' => 'University Student Government',
                    'proponent_email' => 'usg.secretary@usm.edu.ph',
                    'proponent_contact_number' => '09170000000',
                    'rationale' => 'The assembly aligns students on university-wide priorities.',
                    'general_objective' => 'Conduct a university-wide student assembly.',
                    'specific_objectives' => ['Orient students on annual priorities', 'Collect student feedback'],
                    'methodology' => 'Program briefing, open forum, and synthesis.',
                    'expected_output' => 'Documented university-wide assembly outputs.',
                ],
                'start_date' => Carbon::today()->toDateString(),
                'end_date' => Carbon::today()->toDateString(),
                'location_type' => 'on-campus',
                'venue_id' => $venue->id,
                'target_demographics' => [
                    'all_students' => true,
                    'implementing_office' => 'University Student Government',
                    'target_participants' => 'All USM students',
                ],
                'budget_allocations' => [
                    'source_of_fund' => 'USG fund',
                    'budget_cost' => 10000,
                    'program_materials' => 10000,
                ],
                'resolution_number' => 'USG-2026-001',
                'hardcopy_submitted' => '1',
                'head_organization_signed' => '1',
                'adviser_signed' => '1',
                'proposal_document' => UploadedFile::fake()->create('usg-assembly.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'evaluation_questions' => EvaluationQuestions::defaultSchema(),
                'event_days' => [[
                    'day_number' => 7,
                    'date' => Carbon::today()->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('event.organization_id', $usgOrg->id);

        $eventId = $createResponse->json('event.id');
        $this->assertDatabaseHas('events', [
            'id' => $eventId,
            'organization_id' => $usgOrg->id,
            'title' => 'University General Assembly',
            'proposal_category' => 'activity',
            'proposal_document_original_name' => 'usg-assembly.docx',
            'venue_id' => $venue->id,
            'venue_confirmation_document_original_name' => null,
            'resolution_number' => 'USG-2026-001',
            'hardcopy_submitted' => false,
            'head_organization_signed' => false,
            'adviser_signed' => false,
        ]);
        $event = Event::findOrFail($eventId);
        $this->assertDatabaseHas('event_days', [
            'event_id' => $eventId,
            'day_number' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->assertDatabaseHas('evaluation_form_versions', [
            'event_id' => $eventId,
            'title' => 'Evaluation - University General Assembly',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $eventId,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'organization_id' => $usgOrg->id,
            'ppa_softcopy_original_name' => 'usg-assembly.docx',
            'hardcopy_submitted' => false,
            'org_head_signed' => false,
            'adviser_signed' => false,
        ]);
        Storage::disk('local')->assertExists($event->proposal_document_path);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/v1/events/{$eventId}/submit")
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/v1/events/{$eventId}/analytics")
            ->assertStatus(200)
            ->assertJsonPath('attendance.total_demographic', 1);

        $pending = PendingStudentLink::create([
            'event_id' => $eventId,
            'organization_id' => $usgOrg->id,
            'qr_code_value' => 'QR_USG_UNKNOWN',
            'status' => 'pending',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/v1/pending-student-links/{$pending->id}/resolve", [
                'student_id' => $student->id,
                'notes' => 'Cross-organization resolution should remain OSA-only.',
            ])
            ->assertStatus(403);

        $otherEvent = Event::create([
            'organization_id' => $societyOrg->id,
            'title' => 'Society General Assembly',
            'status' => 'draft',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/v1/events/{$otherEvent->id}/submit")
            ->assertStatus(403);
    }

    public function test_student_affairs_api_allows_incomplete_drafts_and_enforces_parent_and_tenant_rules(): void
    {
        Storage::fake('local');

        [$organization, $admin, $college] = $this->createOrganizationWithScanner();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
            'device_name' => 'Proposal API Test',
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $crossTenantOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Cross Tenant Society',
            'acronym' => 'CTS',
            'type' => 'society',
        ]);
        $crossTenantPayload = $this->apiProposalPayload('Cross Tenant Proposal');
        $crossTenantPayload['organization_id'] = $crossTenantOrganization->id;

        $this->withHeaders($headers)
            ->postJson('/api/v1/events', $crossTenantPayload)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Access denied. Restricted to your assigned admin boundary.');

        $incompleteDraft = $this->withHeaders($headers)
            ->postJson('/api/v1/events', $this->apiProposalPayload('Missing Document Proposal'))
            ->assertCreated()
            ->assertJsonPath('event.proposal_document_original_name', null)
            ->assertJsonPath('event.venue_confirmation.evidence_available', false);

        $this->assertDatabaseHas('events', [
            'id' => $incompleteDraft->json('event.id'),
            'status' => Event::STATUS_DRAFT,
            'proposal_document_path' => null,
        ]);

        $invalidParentOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Invalid Parent Society',
            'acronym' => 'IPS',
            'type' => 'society',
        ]);
        $invalidParent = Event::create([
            'organization_id' => $invalidParentOrganization->id,
            'title' => 'Invalid Parent Event',
            'status' => Event::STATUS_APPROVED,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $invalidParentPayload = $this->apiProposalPayload('Invalid Parent Proposal');
        $invalidParentPayload['parent_event_id'] = $invalidParent->id;
        $invalidParentPayload['proposal_document'] = UploadedFile::fake()->create('invalid-parent.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->withHeaders($headers)
            ->post('/api/v1/events', $invalidParentPayload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sub-events can only be linked to USG or LSG parent events.');

        $legacyEvent = Event::create([
            'organization_id' => $organization->id,
            'title' => 'Legacy Proposal Without Document',
            'event_domain' => 'student_affairs',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'location_type' => 'on-campus',
        ]);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/events/{$legacyEvent->id}", $this->apiProposalPayload('Legacy Proposal Updated'))
            ->assertOk()
            ->assertJsonPath('event.title', 'Legacy Proposal Updated')
            ->assertJsonPath('event.proposal_document_original_name', null);

        $otherOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Other Tenant Society',
            'acronym' => 'OTS',
            'type' => 'society',
        ]);
        $otherEvent = Event::create([
            'organization_id' => $otherOrganization->id,
            'title' => 'Other Tenant Proposal',
            'event_domain' => 'student_affairs',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'location_type' => 'on-campus',
        ]);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/events/{$otherEvent->id}", $this->apiProposalPayload('Unauthorized Tenant Update'))
            ->assertStatus(403);
    }

    public function test_revision_requested_api_update_replaces_document_and_preserves_attendance_cutoff(): void
    {
        Storage::fake('local');

        [$organization, $admin] = $this->createOrganizationWithScanner();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
            'device_name' => 'Proposal Revision Test',
        ])->assertOk();

        $oldDocumentPath = 'proposal-documents/original-proposal.docx';
        Storage::disk('local')->put($oldDocumentPath, 'original proposal');

        $event = Event::create([
            'organization_id' => $organization->id,
            'title' => 'Revision Requested Proposal',
            'event_domain' => 'student_affairs',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_REVISION_REQUESTED,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'location_type' => 'on-campus',
            'proposal_document_path' => $oldDocumentPath,
            'proposal_document_original_name' => 'original-proposal.docx',
            'event_late_cutoff_min' => 25,
        ]);
        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);
        app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $payload = $this->apiProposalPayload('Revised API Proposal');
        $payload['proposal_category'] = 'project';
        $payload['resolution_number'] = 'REV-2026-001';
        $payload['hardcopy_submitted'] = '1';
        $payload['head_organization_signed'] = '1';
        $payload['adviser_signed'] = '1';
        $payload['event_late_cutoff_min'] = 99;
        $payload['event_days'][0]['day_number'] = 42;
        $payload['proposal_document'] = UploadedFile::fake()->create('replacement-proposal.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->withHeaders(['Authorization' => 'Bearer '.$login->json('token')])
            ->patch("/api/v1/events/{$event->id}", $payload)
            ->assertOk()
            ->assertJsonPath('event.title', 'Revised API Proposal')
            ->assertJsonPath('event.proposal_category', 'project');

        $event->refresh();
        $this->assertSame(25, $event->event_late_cutoff_min);
        $this->assertSame('replacement-proposal.docx', $event->proposal_document_original_name);
        $this->assertSame('REV-2026-001', $event->resolution_number);
        $this->assertFalse($event->hardcopy_submitted);
        $this->assertFalse($event->head_organization_signed);
        $this->assertFalse($event->adviser_signed);
        $this->assertDatabaseHas('event_days', [
            'event_id' => $event->id,
            'day_number' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->assertDatabaseHas('evaluation_form_versions', [
            'event_id' => $event->id,
            'title' => 'Evaluation - Revised API Proposal',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
            'ppa_softcopy_original_name' => 'replacement-proposal.docx',
            'hardcopy_submitted' => false,
            'org_head_signed' => false,
            'adviser_signed' => false,
        ]);
        Storage::disk('local')->assertMissing($oldDocumentPath);
        Storage::disk('local')->assertExists($event->proposal_document_path);
    }

    public function test_unresolved_qr_sync_creates_pending_link_and_can_be_resolved()
    {
        [$org, $scanner, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->syncWithoutDetaching($program->id);

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Approved Seminar',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $token = $this->scannerTokenForEvent($event, $scanner);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'unknown-qr-1',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'device_unknown',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_UNKNOWN',
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'ingested_count' => 1,
                'unresolved_count' => 1,
            ]);

        $this->assertEquals(1, PendingStudentLink::count());
        $pending = PendingStudentLink::first();
        $this->assertSame('pending', $pending->status);
        $this->assertSame('QR_UNKNOWN', $pending->qr_code_value);
        $this->assertNull(RawScan::first()->student_id);

        $student = User::create([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'department_id' => $program->department_id,
            'program_id' => $program->id,
            'student_id_number' => '23-5678',
            'qr_code_value' => 'QR_MARIA_5678',
            'program_code' => $program->code,
        ]);
        $student->assignRole('Student');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/v1/pending-student-links/{$pending->id}/resolve", [
                'student_id' => $student->id,
                'notes' => 'Matched after profile confirmation.',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'resolved']);

        $pending->refresh();
        $this->assertSame('resolved', $pending->status);
        $this->assertSame($student->id, $pending->resolved_student_id);
        $this->assertSame($student->id, RawScan::first()->student_id);
    }

    public function test_assignment_activation_drives_hydrate_sync_and_eilo_resolution()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Assignment Scanner Student',
            'email' => 'assignment.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3100',
            'qr_code_value' => 'QR_ASSIGNMENT_SCANNER',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Assignment Scanner Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'society_late_threshold_min' => 15,
            'general_competition_threshold_min' => 30,
            'left_early_buffer_min' => 15,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Main Gate'
        );

        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(403);

        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'device_assignment_scanner',
            ])
            ->assertStatus(200)
            ->assertJsonPath('scanner_session.assignment_id', $assignment->id);

        $token = $activation->json('token');

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', [
                'event_id' => $event->id,
                'device_id' => 'device_assignment_scanner',
            ])
            ->assertStatus(200)
            ->assertJsonPath('event.id', $event->id);

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=device_assignment_scanner")
            ->assertStatus(200)
            ->assertJsonFragment(['qr_code_value' => 'QR_ASSIGNMENT_SCANNER'])
            ->assertJsonFragment(['student_id_number' => '24-3100']);

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [
                    [
                        'dedup_key' => 'assignment-scanner-in',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                        'device_id' => 'device_assignment_scanner',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_ASSIGNMENT_SCANNER',
                    ],
                    [
                        'dedup_key' => 'assignment-scanner-in',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 1, 0)->toDateTimeString(),
                        'device_id' => 'device_assignment_scanner',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_ASSIGNMENT_SCANNER',
                    ],
                    [
                        'dedup_key' => 'assignment-scanner-out',
                        'scan_type' => 'time_out',
                        'scanned_at' => Carbon::today()->setTime(12, 0, 0)->toDateTimeString(),
                        'device_id' => 'device_assignment_scanner',
                        'manual_entry' => true,
                        'qr_code_value' => 'QR_ASSIGNMENT_SCANNER',
                    ],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'ingested_count' => 2,
                'duplicate_count' => 1,
                'unresolved_count' => 0,
            ]);

        $this->assertSame(2, RawScan::where('event_id', $event->id)->count());

        (new ResolveAttendanceJob($event->id, [$student->id]))->handle(app(EventAnalyticsCache::class));

        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $student->id,
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => false,
        ]);
    }

    public function test_student_token_cannot_open_scanner_session()
    {
        [$org] = $this->createOrganizationWithScanner();

        $student = User::create([
            'name' => 'Unauthorized Scanner Student',
            'email' => 'unauthorized.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3200',
            'qr_code_value' => 'QR_UNAUTHORIZED_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Student Token Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $token = $student->createToken('student-phone')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This token is not authorized for scanner sessions.',
            ]);
    }

    public function test_assigned_student_can_activate_scanner_session_but_mobile_token_cannot_hydrate()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Assigned Scanner Student',
            'email' => 'assigned.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3300',
            'qr_code_value' => 'QR_ASSIGNED_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Main Gate'
        );

        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->getJson('/api/v1/scanner/assignments')
            ->assertStatus(200)
            ->assertJsonPath('assignments.0.id', $assignment->id)
            ->assertJsonPath('assignments.0.activation_allowed', true);

        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'assigned-device-1',
            ])
            ->assertStatus(200)
            ->assertJsonPath('scanner_session.assignment_id', $assignment->id);

        $scannerToken = $activation->json('token');
        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=assigned-device-1")
            ->assertStatus(200)
            ->assertJsonFragment(['qr_code_value' => 'QR_ASSIGNED_STUDENT']);
    }

    public function test_unassigned_student_cannot_activate_another_users_assignment()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $assignedStudent = User::create([
            'name' => 'Owner Scanner Student',
            'email' => 'owner.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3301',
            'qr_code_value' => 'QR_OWNER_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $assignedStudent->assignRole('Student');

        $unassignedStudent = User::create([
            'name' => 'Unassigned Scanner Student',
            'email' => 'unassigned.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3302',
            'qr_code_value' => 'QR_UNASSIGNED_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $unassignedStudent->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $assignedStudent,
            assignedBy: $admin,
            label: 'Side Gate'
        );

        $mobileToken = $unassignedStudent->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'wrong-device',
            ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This scanner assignment belongs to another user.',
            ]);
    }

    public function test_revoked_assignment_blocks_new_activation_but_allows_pending_sync_during_grace()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Revoked Scanner Student',
            'email' => 'revoked.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3303',
            'qr_code_value' => 'QR_REVOKED_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Recovery Gate'
        );

        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'revoked-device',
            ])
            ->assertStatus(200);

        $scannerToken = $activation->json('token');
        app(ScannerAssignmentService::class)->revoke($assignment, $admin, 'Assignment revoked during event.');

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'revoked-device',
            ])
            ->assertStatus(423)
            ->assertJsonFragment(['reason' => 'assignment_inactive']);

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=revoked-device")
            ->assertStatus(401)
            ->assertJsonFragment(['reason' => 'revoked']);

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'revoked-assignment-pending-scan',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->setTime(8, 0, 0)->toDateTimeString(),
                    'device_id' => 'revoked-device',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_REVOKED_STUDENT',
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['ingested_count' => 1]);
    }

    public function test_assignment_activation_resumes_prior_session_for_same_device()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Replacement Scanner Student',
            'email' => 'replacement.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3310',
            'qr_code_value' => 'QR_REPLACEMENT_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 1'
        );
        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $firstActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200);

        $firstToken = $firstActivation->json('token');
        $firstSession = ScannerSession::findOrFail($firstActivation->json('scanner_session.id'));
        $this->assertSame(ScannerSession::STATUS_ACTIVE, $firstSession->status);

        Auth::forgetGuards();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$mobileToken,
            'X-Device-Id' => 'gate-1-device',
        ])
            ->getJson('/api/v1/scanner/assignments')
            ->assertStatus(200)
            ->assertJsonPath('assignments.0.activation_state', 'resume')
            ->assertJsonPath('assignments.0.active_session_device_matches', true);

        Auth::forgetGuards();

        $secondActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200)
            ->assertJsonPath('resumed', true)
            ->assertJsonPath('message', 'Scanner assignment resumed.')
            ->assertJsonPath('scanner_session.id', $firstSession->id);

        $firstSession->refresh();
        $this->assertSame(ScannerSession::STATUS_ACTIVE, $firstSession->status);
        $this->assertSame(1, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_ACTIVE)->count());
        $this->assertSame(0, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_REVOKED)->count());

        $secondToken = $secondActivation->json('token');
        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$secondToken])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id, 'device_id' => 'gate-1-device'])
            ->assertStatus(200);
    }

    public function test_assignment_activation_blocks_different_device_without_revoking_old_session()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Device Transfer Scanner Student',
            'email' => 'device.transfer.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3311',
            'qr_code_value' => 'QR_DEVICE_TRANSFER_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 1'
        );
        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $firstActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200);

        $firstToken = $firstActivation->json('token');
        $firstSession = ScannerSession::findOrFail($firstActivation->json('scanner_session.id'));

        Auth::forgetGuards();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$mobileToken,
            'X-Device-Id' => 'gate-1-replacement',
        ])
            ->getJson('/api/v1/scanner/assignments')
            ->assertStatus(200)
            ->assertJsonPath('assignments.0.activation_state', 'blocked_active_session')
            ->assertJsonPath('assignments.0.active_session_device_matches', false);

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-replacement',
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['reason' => 'active_session_exists']);

        $firstSession->refresh();
        $this->assertSame(ScannerSession::STATUS_ACTIVE, $firstSession->status);
        $this->assertSame(1, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_ACTIVE)->count());
        $this->assertSame(0, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_REVOKED)->count());

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$firstToken])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id, 'device_id' => 'gate-1-device'])
            ->assertStatus(200);
    }

    public function test_admin_revoke_allows_assignment_activation_on_another_device()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Admin Transfer Scanner Student',
            'email' => 'admin.transfer.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3312',
            'qr_code_value' => 'QR_ADMIN_TRANSFER_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = $this->createApprovedEvent($org);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 1'
        );
        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $firstActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200);

        $firstSession = ScannerSession::findOrFail($firstActivation->json('scanner_session.id'));
        app(ScannerSessionService::class)->revoke($firstSession, $admin, 'Admin approved scanner device transfer.');

        Auth::forgetGuards();

        $secondActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'gate-1-replacement',
            ])
            ->assertStatus(200)
            ->assertJsonPath('resumed', false);

        $this->assertNotSame($firstSession->id, $secondActivation->json('scanner_session.id'));
        $this->assertSame(1, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_ACTIVE)->count());
        $this->assertSame(1, ScannerSession::where('event_id', $event->id)->where('status', ScannerSession::STATUS_REVOKED)->count());
    }

    public function test_assignment_activation_blocks_other_active_assignment_without_revoking()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Multi Assignment Scanner Student',
            'email' => 'multi.assignment.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3313',
            'qr_code_value' => 'QR_MULTI_ASSIGNMENT_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $firstEvent = $this->createApprovedEvent($org);
        $secondEvent = $this->createApprovedEvent($org);
        $secondEvent->update(['title' => 'Second Scanner Event']);
        $firstAssignment = app(ScannerAssignmentService::class)->assign(
            event: $firstEvent,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 1'
        );
        $secondAssignment = app(ScannerAssignmentService::class)->assign(
            event: $secondEvent,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 2'
        );
        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $firstActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$firstAssignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200);

        $firstSession = ScannerSession::findOrFail($firstActivation->json('scanner_session.id'));

        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$secondAssignment->id}/activate", [
                'device_id' => 'gate-2-device',
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['reason' => 'active_session_exists']);

        $firstSession->refresh();
        $this->assertSame(ScannerSession::STATUS_ACTIVE, $firstSession->status);
        $this->assertSame(0, ScannerSession::where('event_id', $firstEvent->id)->where('status', ScannerSession::STATUS_REVOKED)->count());
        $this->assertSame(0, ScannerSession::where('event_id', $secondEvent->id)->count());
    }

    public function test_stale_session_from_closed_assignment_does_not_block_new_assignment_activation()
    {
        [$org, $admin, $college] = $this->createOrganizationWithScanner();
        $program = $this->createProgramForCollege($college);
        $org->programs()->attach($program->id);

        $student = User::create([
            'name' => 'Stale Session Scanner Student',
            'email' => 'stale.session.scanner.student@usm.edu.ph',
            'organization_id' => $org->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-3314',
            'qr_code_value' => 'QR_STALE_SESSION_STUDENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $firstEvent = $this->createApprovedEvent($org);
        $secondEvent = $this->createApprovedEvent($org);
        $secondEvent->update(['title' => 'Second Scanner Event']);
        $firstAssignment = app(ScannerAssignmentService::class)->assign(
            event: $firstEvent,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 1'
        );
        $secondAssignment = app(ScannerAssignmentService::class)->assign(
            event: $secondEvent,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gate 2'
        );
        $mobileToken = $student->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;

        $firstActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$firstAssignment->id}/activate", [
                'device_id' => 'gate-1-device',
            ])
            ->assertStatus(200);

        $firstSession = ScannerSession::findOrFail($firstActivation->json('scanner_session.id'));
        $firstAssignment->markRevoked($admin, 'Interrupted dashboard revoke left session stale.');

        Auth::forgetGuards();

        $secondActivation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$secondAssignment->id}/activate", [
                'device_id' => 'gate-2-device',
            ])
            ->assertStatus(200)
            ->assertJsonPath('resumed', false);

        $firstSession->refresh();
        $this->assertSame(ScannerSession::STATUS_ACTIVE, $firstSession->status);
        $this->assertNotSame($firstSession->id, $secondActivation->json('scanner_session.id'));
        $this->assertSame(1, ScannerSession::where('event_id', $secondEvent->id)->where('status', ScannerSession::STATUS_ACTIVE)->count());
    }

    public function test_device_bound_scanner_session_rejects_different_device()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();
        $event = $this->createApprovedEvent($org);
        $token = $this->scannerTokenForEvent($event, $scanner);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id, 'device_id' => 'device-a'])
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id, 'device_id' => 'device-b'])
            ->assertStatus(403)
            ->assertJsonFragment(['reason' => 'device_mismatch']);
    }

    public function test_scanner_validation_serializes_event_local_scan_window()
    {
        config([
            'services.eaes.scan_early_checkin_buffer_min' => 60,
            'services.eaes.checkout_grace_period_min' => 60,
        ]);

        $eventDate = Carbon::today('Asia/Manila');
        Carbon::setTestNow($eventDate->copy()->setTime(10, 0));

        try {
            [$org, $scanner] = $this->createOrganizationWithScanner();
            $event = $this->createApprovedEvent($org, $eventDate);
            $event->eventDays()->update([
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
            ]);
            $token = $this->scannerTokenForEvent($event, $scanner);
            $eventDay = $event->eventDays()->firstOrFail();
            $eventTimezone = config('services.eaes.event_timezone', 'Asia/Manila');
            $expectedScanWindowStart = Carbon::parse(
                $eventDay->date->toDateString().' '.$eventDay->start_time,
                $eventTimezone
            )->subHour()->toIso8601String();
            $expectedScanWindowEnd = Carbon::parse(
                $eventDay->date->toDateString().' '.$eventDay->end_time,
                $eventTimezone
            )->addHour()->toIso8601String();
            $expectedTimeInWindowEnd = Carbon::parse(
                $eventDay->date->toDateString().' '.$eventDay->end_time,
                $eventTimezone
            )->toIso8601String();
            $expectedTimeOutWindowStart = Carbon::parse(
                $eventDay->date->toDateString().' '.$eventDay->start_time,
                $eventTimezone
            )->toIso8601String();

            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id, 'device_id' => 'device-a'])
                ->assertStatus(200)
                ->assertJsonPath('scanner_session.scan_window_start', $expectedScanWindowStart)
                ->assertJsonPath('scanner_session.scan_window_end', $expectedScanWindowEnd)
                ->assertJsonPath('scanner_session.time_in_window_start', $expectedScanWindowStart)
                ->assertJsonPath('scanner_session.time_in_window_end', $expectedTimeInWindowEnd)
                ->assertJsonPath('scanner_session.time_out_window_start', $expectedTimeOutWindowStart)
                ->assertJsonPath('scanner_session.time_out_window_end', $expectedScanWindowEnd);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_scanner_session_rejects_sync_with_clear_response()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();
        $event = $this->createApprovedEvent($org, Carbon::today()->subDays(5));
        $token = $this->scannerTokenForEvent($event, $scanner);

        ScannerSession::where('event_id', $event->id)->first()->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [[
                    'dedup_key' => 'expired-session-scan',
                    'scan_type' => 'time_in',
                    'scanned_at' => Carbon::today()->subDays(5)->setTime(8, 0)->toDateTimeString(),
                    'device_id' => 'expired-device',
                    'manual_entry' => false,
                    'qr_code_value' => 'QR_EXPIRED',
                ]],
            ])
            ->assertStatus(401)
            ->assertJsonFragment(['reason' => 'expired']);
    }

    public function test_sync_rejects_scans_outside_event_window_per_scan()
    {
        config([
            'services.eaes.scan_early_checkin_buffer_min' => 60,
            'services.eaes.checkout_grace_period_min' => 60,
        ]);

        [$org, $scanner] = $this->createOrganizationWithScanner();
        $event = $this->createApprovedEvent($org);
        $token = $this->scannerTokenForEvent($event, $scanner);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [
                    [
                        'dedup_key' => 'too-early',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(6, 59)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_TOO_EARLY',
                    ],
                    [
                        'dedup_key' => 'valid-early-checkin',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(7, 30)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_VALID_EARLY_CHECKIN',
                    ],
                    [
                        'dedup_key' => 'valid-window',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 1)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_VALID_WINDOW',
                    ],
                    [
                        'dedup_key' => 'time-in-after-end',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(12, 1)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_TIME_IN_AFTER_END',
                    ],
                    [
                        'dedup_key' => 'valid-late-timeout',
                        'scan_type' => 'time_out',
                        'scanned_at' => Carbon::today()->setTime(12, 1)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_VALID_LATE_TIMEOUT',
                    ],
                    [
                        'dedup_key' => 'too-late-timeout',
                        'scan_type' => 'time_out',
                        'scanned_at' => Carbon::today()->setTime(13, 1)->toDateTimeString(),
                        'device_id' => 'window-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_TOO_LATE_TIMEOUT',
                    ],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'ingested_count' => 3,
                'rejected_count' => 3,
            ]);

        $this->assertDatabaseHas('raw_scans', ['dedup_key' => 'valid-early-checkin']);
        $this->assertDatabaseHas('raw_scans', ['dedup_key' => 'valid-window']);
        $this->assertDatabaseHas('raw_scans', ['dedup_key' => 'valid-late-timeout']);
        $this->assertDatabaseMissing('raw_scans', ['dedup_key' => 'too-early']);
        $this->assertDatabaseMissing('raw_scans', ['dedup_key' => 'time-in-after-end']);
        $this->assertDatabaseMissing('raw_scans', ['dedup_key' => 'too-late-timeout']);
    }

    public function test_lifecycle_command_transitions_event_phases_and_completion()
    {
        [$org, $scanner] = $this->createOrganizationWithScanner();
        $event = $this->createApprovedEvent($org, Carbon::today());

        Carbon::setTestNow(Carbon::today('Asia/Manila')->setTime(7, 0));
        $this->artisan('eaes:update-event-lifecycles')->assertSuccessful();
        $this->assertSame(EventLifecycleService::PHASE_SCHEDULED, $event->fresh()->lifecycle_phase);

        Carbon::setTestNow(Carbon::today('Asia/Manila')->setTime(9, 0));
        $this->artisan('eaes:update-event-lifecycles')->assertSuccessful();
        $this->assertSame(EventLifecycleService::PHASE_ACTIVE, $event->fresh()->lifecycle_phase);

        Carbon::setTestNow(Carbon::today('Asia/Manila')->setTime(13, 0));
        $this->artisan('eaes:update-event-lifecycles')->assertSuccessful();
        $this->assertSame(EventLifecycleService::PHASE_EVALUATION_OPEN, $event->fresh()->lifecycle_phase);

        $this->scannerTokenForEvent($event, $scanner);
        Carbon::setTestNow(Carbon::today()->addDays(2));
        $this->artisan('eaes:update-event-lifecycles')->assertSuccessful();
        $this->assertSame(EventLifecycleService::PHASE_PROCESSING, $event->fresh()->lifecycle_phase);

        ScannerSession::where('event_id', $event->id)->update(['status' => ScannerSession::STATUS_REVOKED]);
        $this->artisan('eaes:update-event-lifecycles')->assertSuccessful();
        $event->refresh();
        $this->assertSame(EventLifecycleService::PHASE_COMPLETED, $event->lifecycle_phase);
        $this->assertSame('completed', $event->status);

        Carbon::setTestNow();
    }

    public function test_scanner_token_rejects_cross_tenant_event_access()
    {
        [$ownOrg, $societyAdmin] = $this->createOrganizationWithScanner();
        [$otherOrg] = $this->createOrganizationWithScanner('College of Science', 'COS', 'Science Society', 'SCI');

        $event = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Tenant Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $token = $societyAdmin->createToken('cross-tenant-scanner', ['scan-qr-codes'])->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/scanner/validate', ['event_id' => $event->id])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Unauthorized event boundary check.']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/v1/events/{$event->id}/hydrate")
            ->assertStatus(403);

        $this->assertNotSame($ownOrg->id, $otherOrg->id);
    }

    public function test_pending_participant_ppa_blocks_scanner_assignment_and_runtime_access()
    {
        [$organizerOrg, $organizerAdmin] = $this->createOrganizationWithScanner();
        [$participantOrg, $participantAdmin, $college] = $this->createOrganizationWithScanner('College of Architecture', 'COA', 'Architecture Society', 'ARCH');
        $program = $this->createProgramForCollege($college, 'BS Architecture', 'BSARCH');
        $participantOrg->programs()->attach($program->id);

        $scanner = User::create([
            'name' => 'Participant Scanner',
            'email' => 'participant.scanner@usm.edu.ph',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-4401',
            'qr_code_value' => 'QR_PARTICIPANT_SCANNER',
            'program_code' => 'BSARCH',
        ]);
        $scanner->assignRole('Student');

        $event = $this->createApprovedEvent($organizerOrg);
        $parentScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event);
        $participantScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $parentScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'participant_name_snapshot' => 'Architecture Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        try {
            app(ScannerAssignmentService::class)->assign(
                event: $event,
                scannerUser: $scanner,
                assignedBy: $participantAdmin,
                participationScope: $participantScope
            );
            $this->fail('Pending participant PPA should block scanner assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_participation_scope_id', $exception->errors());
        }

        $participantScope->update(['ppa_status' => EventParticipationScope::PPA_APPROVED, 'approved_at' => now()]);
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $scanner,
            assignedBy: $participantAdmin,
            participationScope: $participantScope
        );
        $mobileToken = $scanner->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", [
                'device_id' => 'participant-device',
            ])
            ->assertStatus(200)
            ->assertJsonPath('scanner_session.event_participation_scope_id', $participantScope->id);

        $scannerToken = $activation->json('token');
        $participantScope->update(['ppa_status' => EventParticipationScope::PPA_REJECTED]);
        Auth::forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=participant-device")
            ->assertStatus(423)
            ->assertJsonFragment(['reason' => 'participant_ppa_locked']);
    }

    public function test_scoped_hydration_and_sync_reject_out_of_scope_scan()
    {
        [$organizerOrg, $organizerAdmin] = $this->createOrganizationWithScanner();
        [$scopeOrg, $scopeAdmin, $scopeCollege] = $this->createOrganizationWithScanner('College of Computing', 'COC', 'Computing Society', 'COMP');
        [$otherOrg, , $otherCollege] = $this->createOrganizationWithScanner('College of Business', 'COB', 'Business Society', 'BUS');

        $scopeProgram = $this->createProgramForCollege($scopeCollege, 'BS Computing', 'BSCOMP');
        $otherProgram = $this->createProgramForCollege($otherCollege, 'BS Business', 'BSBA');

        $scanner = User::create([
            'name' => 'Scoped Scanner',
            'email' => 'scoped.scanner@usm.edu.ph',
            'organization_id' => $scopeOrg->id,
            'college_id' => $scopeCollege->id,
            'program_id' => $scopeProgram->id,
            'student_id_number' => '24-4501',
            'qr_code_value' => 'QR_SCOPE_SCANNER',
            'program_code' => 'BSCOMP',
        ]);
        $scanner->assignRole('Student');

        $outsideStudent = User::create([
            'name' => 'Outside Student',
            'email' => 'outside.student@usm.edu.ph',
            'organization_id' => $otherOrg->id,
            'college_id' => $otherCollege->id,
            'program_id' => $otherProgram->id,
            'student_id_number' => '24-4502',
            'qr_code_value' => 'QR_OUTSIDE_SCOPE',
            'program_code' => 'BSBA',
        ]);
        $outsideStudent->assignRole('Student');

        $event = $this->createApprovedEvent($organizerOrg);
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => app(ParticipationScopeService::class)->ensureOrganizerScope($event)->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $scopeOrg->id,
            'college_id' => $scopeCollege->id,
            'participant_name_snapshot' => 'Computing Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'approved_at' => now(),
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $scanner,
            assignedBy: $scopeAdmin,
            participationScope: $scope
        );
        $mobileToken = $scanner->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $activation = $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$assignment->id}/activate", ['device_id' => 'scope-device'])
            ->assertStatus(200);
        $scannerToken = $activation->json('token');

        Auth::forgetGuards();
        $hydrate = $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->getJson("/api/v1/events/{$event->id}/hydrate?device_id=scope-device")
            ->assertStatus(200)
            ->assertJsonFragment(['qr_code_value' => 'QR_SCOPE_SCANNER']);
        $this->assertStringNotContainsString('QR_OUTSIDE_SCOPE', $hydrate->getContent());

        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $event->id,
                'scans' => [
                    [
                        'dedup_key' => 'scope-valid',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 0)->toDateTimeString(),
                        'device_id' => 'scope-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_SCOPE_SCANNER',
                    ],
                    [
                        'dedup_key' => 'scope-invalid',
                        'scan_type' => 'time_in',
                        'scanned_at' => Carbon::today()->setTime(8, 1)->toDateTimeString(),
                        'device_id' => 'scope-device',
                        'manual_entry' => false,
                        'qr_code_value' => 'QR_OUTSIDE_SCOPE',
                    ],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['ingested_count' => 1, 'rejected_count' => 1])
            ->assertJsonFragment(['reason' => 'out_of_scope']);

        $this->assertDatabaseHas('raw_scans', [
            'dedup_key' => 'scope-valid',
            'event_participation_scope_id' => $scope->id,
            'student_id' => $scanner->id,
        ]);
        $this->assertDatabaseMissing('raw_scans', ['dedup_key' => 'scope-invalid']);
        $this->assertSame($outsideStudent->id, User::where('qr_code_value', 'QR_OUTSIDE_SCOPE')->first()->id);
    }

    public function test_hr_admin_can_create_hr_event_without_osa_ppa(): void
    {
        $hrOrg = Organization::create([
            'name' => 'Human Resource Management Office',
            'acronym' => 'HRMO',
            'type' => 'hr_unit',
        ]);
        $hrAdmin = User::create([
            'name' => 'HR Admin',
            'email' => 'hr.admin@usm.edu.ph',
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);
        $this->assignAdminScope($hrAdmin, 'HR Admin', $hrOrg, null);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'hr.admin@usm.edu.ph',
            'password' => 'password123',
            'device_name' => 'HR Workstation',
        ])->assertStatus(200);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$login->json('token')])
            ->postJson('/api/v1/events', [
                'title' => 'Faculty Assembly Attendance',
                'proposal_category' => 'activity',
                'start_date' => Carbon::today()->toDateString(),
                'end_date' => Carbon::today()->toDateString(),
                'location_type' => 'on-campus',
                'location_details' => 'HR Training Room',
                'evaluation_questions' => EvaluationQuestions::defaultSchema(),
                'event_days' => [[
                    'day_number' => 1,
                    'date' => Carbon::today()->toDateString(),
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('event.status', 'approved')
            ->assertJsonPath('event.event_domain', 'hr');

        $event = Event::findOrFail($response->json('event.id'));
        $scope = $event->participationScopes()->first();
        $this->assertNotNull($scope);
        $this->assertFalse($scope->ppa_required);
        $this->assertSame(EventParticipationScope::PPA_NOT_REQUIRED, $scope->ppa_status);
    }

    public function test_global_analytics_dedupes_user_across_scopes_while_preserving_scope_counts()
    {
        [$organizerOrg, $organizerAdmin] = $this->createOrganizationWithScanner();
        [$firstOrg, , $college] = $this->createOrganizationWithScanner('College of Multi Org', 'CMO', 'First Society', 'FIRST');
        $secondOrg = Organization::create([
            'college_id' => $college->id,
            'name' => 'Second Society',
            'acronym' => 'SECOND',
            'type' => 'society',
        ]);
        $program = $this->createProgramForCollege($college, 'BS Multi', 'BSM');

        $student = User::create([
            'name' => 'Multi Member',
            'email' => 'multi.member@usm.edu.ph',
            'organization_id' => $firstOrg->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '24-4601',
            'qr_code_value' => 'QR_MULTI_MEMBER',
            'program_code' => 'BSM',
        ]);
        $student->assignRole('Student');
        OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $secondOrg->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'membership_type' => 'secondary_org',
            'status' => 'active',
            'source' => 'org_verified',
        ]);

        $event = $this->createApprovedEvent($organizerOrg);
        $parentScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event);
        $firstScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $parentScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $firstOrg->id,
            'college_id' => $college->id,
            'participant_name_snapshot' => 'First Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);
        $secondScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $parentScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $secondOrg->id,
            'college_id' => $college->id,
            'participant_name_snapshot' => 'Second Society',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $firstScope->id,
            'student_id' => $student->id,
            'time_in' => Carbon::today()->setTime(8, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $secondScope->id,
            'student_id' => $student->id,
            'time_in' => Carbon::today()->setTime(8, 5),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);

        $token = $organizerAdmin->createToken('admin')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson("/api/v1/events/{$event->id}/analytics")
            ->assertStatus(200)
            ->assertJsonPath('attendance.global_unique_attended', 1)
            ->assertJsonPath('attendance.total_demographic', 1);

        $rollups = collect($response->json('attendance.participant_scopes'));
        $this->assertSame(1, $rollups->firstWhere('id', $firstScope->id)['attended']);
        $this->assertSame(1, $rollups->firstWhere('id', $secondScope->id)['attended']);
    }

    public function test_nlp_query_is_scoped_to_the_requesting_admin_organization()
    {
        [$ownOrg, $societyAdmin] = $this->createOrganizationWithScanner();
        [$otherOrg] = $this->createOrganizationWithScanner('College of Agriculture', 'COA', 'Aggies Society', 'AGS');

        $ownStudent = User::create([
            'name' => 'Own Student',
            'email' => 'own.student@usm.edu.ph',
            'organization_id' => $ownOrg->id,
            'student_id_number' => '24-1001',
            'qr_code_value' => 'QR_OWN',
            'program_code' => 'BSIS',
        ]);
        $ownStudent->assignRole('Student');

        $otherStudent = User::create([
            'name' => 'Other Student',
            'email' => 'other.student@usm.edu.ph',
            'organization_id' => $otherOrg->id,
            'student_id_number' => '24-2002',
            'qr_code_value' => 'QR_OTHER',
            'program_code' => 'BSA',
        ]);
        $otherStudent->assignRole('Student');

        $ownEvent = Event::create([
            'organization_id' => $ownOrg->id,
            'title' => 'Own Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);
        $otherEvent = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);

        $ownRecord = AttendanceRecord::create([
            'event_id' => $ownEvent->id,
            'student_id' => $ownStudent->id,
            'time_in' => Carbon::today()->setTime(8, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);

        AttendanceRecord::create([
            'event_id' => $otherEvent->id,
            'student_id' => $otherStudent->id,
            'time_in' => Carbon::today()->setTime(8, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService
        {
            public function __construct() {}

            public function parseNaturalLanguageQuery(string $query, User $user): array
            {
                return [
                    'target_table' => 'attendance_records',
                    'filters' => [
                        [
                            'field' => 'organization_id',
                            'operator' => '=',
                            'value' => 'malicious-cross-tenant-filter',
                        ],
                    ],
                ];
            }
        });

        $token = $societyAdmin->createToken('Admin')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/admin/nlp-query', [
                'query' => 'Show all attendance records.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('target_table', 'attendance_records')
            ->assertJsonCount(1, 'ignored_filters')
            ->assertJsonCount(1, 'results');

        $this->assertSame($ownRecord->id, $response->json('results.0.id'));
    }

    public function test_gemini_sentiment_primary_success_is_sanitized()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'results' => [
                                    ['id' => 'eval-1', 'sentiment' => 'excited', 'score' => 1.4],
                                    ['id' => 'eval-2', 'sentiment' => 'negative', 'score' => -0.2],
                                ],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $results = (new GeminiService)->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Great event.'],
            ['id' => 'eval-2', 'comment' => 'Venue was hot.'],
        ]);

        $this->assertSame('eval-1', $results[0]['id']);
        $this->assertSame('neutral', $results[0]['sentiment']);
        $this->assertSame(1.0, $results[0]['score']);
        $this->assertSame('negative', $results[1]['sentiment']);
        $this->assertSame(0.0, $results[1]['score']);
    }

    public function test_gemini_sentiment_falls_back_to_flash()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response('rate limit', 429),
            '*gemini-2.0-flash*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'results' => [
                                    ['id' => 'eval-1', 'sentiment' => 'positive', 'score' => 0.91],
                                ],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $results = (new GeminiService)->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Very useful.'],
        ]);

        $this->assertSame('positive', $results[0]['sentiment']);
        $this->assertSame(0.91, $results[0]['score']);
    }

    public function test_gemini_sentiment_returns_empty_when_models_fail_or_return_malformed_json()
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'gemini-1.5-pro',
            'services.gemini.fallback_model' => 'gemini-2.0-flash',
        ]);

        Http::fake([
            '*gemini-1.5-pro*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'not-json']],
                    ],
                ]],
            ], 200),
            '*gemini-2.0-flash*' => Http::response('server error', 500),
        ]);

        $results = (new GeminiService)->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Comment.'],
        ]);

        $this->assertSame([], $results);
    }

    public function test_gemini_sentiment_missing_api_key_returns_neutral_fallback()
    {
        config(['services.gemini.api_key' => null]);

        $results = (new GeminiService)->analyzeSentiments([
            ['id' => 'eval-1', 'comment' => 'Comment.'],
        ]);

        $this->assertSame([
            ['id' => 'eval-1', 'sentiment' => 'neutral', 'score' => 0.5],
        ], $results);
    }

    public function test_sentiment_job_updates_only_unprocessed_non_blank_comments()
    {
        [$org] = $this->createOrganizationWithScanner();
        $student = User::create([
            'name' => 'Sentiment Student',
            'email' => 'sentiment.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3001',
            'qr_code_value' => 'QR_SENTIMENT',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Sentiment Event',
            'status' => 'completed',
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->subDays(3),
        ]);

        $target = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Helpful and clear.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $blank = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 3],
            'open_comment' => '   ',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $existing = Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 4],
            'open_comment' => 'Already processed.',
            'sentiment' => 'positive',
            'sentiment_score' => 0.8,
            'submitted_at' => now(),
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService
        {
            public function __construct() {}

            public function analyzeSentiments(array $comments): array
            {
                return array_map(fn ($comment) => [
                    'id' => $comment['id'],
                    'sentiment' => 'negative',
                    'score' => 0.22,
                ], $comments);
            }
        });
        $cache = app(EventAnalyticsCache::class);
        $summaryCalls = 0;

        $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        (new AnalyzeEventSentimentsJob($event->id))->handle(
            app(AiServiceInterface::class),
            $cache,
        );

        $summary = $cache->remember($event, function () use (&$summaryCalls): array {
            $summaryCalls++;

            return ['calls' => $summaryCalls];
        });

        $this->assertSame('negative', $target->fresh()->sentiment);
        $this->assertSame(0.22, $target->fresh()->sentiment_score);
        $this->assertSame('unprocessed', $blank->fresh()->sentiment);
        $this->assertSame('positive', $existing->fresh()->sentiment);
        $this->assertSame(['calls' => 2], $summary);
    }

    public function test_closed_evaluation_command_dispatches_only_closed_events_with_unprocessed_comments()
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        [$org] = $this->createOrganizationWithScanner();
        $student = User::create([
            'name' => 'Command Student',
            'email' => 'command.student@usm.edu.ph',
            'organization_id' => $org->id,
            'student_id_number' => '24-3002',
            'qr_code_value' => 'QR_COMMAND',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $closedEvent = Event::create([
            'organization_id' => $org->id,
            'title' => 'Closed Evaluation Event',
            'status' => 'completed',
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->subDays(3),
        ]);
        EventDay::create([
            'event_id' => $closedEvent->id,
            'day_number' => 1,
            'date' => Carbon::today()->subDays(3),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Evaluation::create([
            'event_id' => $closedEvent->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Ready to process.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $openEvent = Event::create([
            'organization_id' => $org->id,
            'title' => 'Open Evaluation Event',
            'status' => 'completed',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
        ]);
        EventDay::create([
            'event_id' => $openEvent->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00',
            'end_time' => '23:00',
        ]);
        Evaluation::create([
            'event_id' => $openEvent->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Still open.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $this->artisan('eaes:analyze-closed-evaluations')
            ->expectsOutput('Dispatched 1 sentiment analysis job(s).')
            ->assertSuccessful();

        Bus::assertDispatched(AnalyzeEventSentimentsJob::class, 1);
    }

    public function test_organizer_event_late_cutoff_overrides_participant_scope_cutoff(): void
    {
        [$organizerOrg,, $college] = $this->createOrganizationWithScanner();

        $participantOrg = Organization::create([
            'college_id' => $college->id,
            'name' => 'Participant Society',
            'acronym' => 'PART',
            'type' => 'society',
        ]);

        $student = User::create([
            'name' => 'Participant Student',
            'email' => 'participant.student@usm.edu.ph',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'student_id_number' => '26-1001',
            'qr_code_value' => 'QR-PARTICIPANT-CUTOFF',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $organizerOrg->id,
            'title' => 'Organizer Cutoff Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'event_late_cutoff_min' => 30,
        ]);

        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $scope = $event->participationScopes()->create([
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'participant_name_snapshot' => 'PART - Participant Society',
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'late_cutoff_min' => 10,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        RawScan::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'student_id' => $student->id,
            'scan_type' => 'time_in',
            'scanned_at' => Carbon::today()->setTime(8, 20),
            'server_received_at' => now(),
            'device_id' => 'policy-test-device',
            'manual_entry' => false,
            'dedup_key' => 'policy-event-wins',
        ]);

        (new ResolveAttendanceJob($event->id, [$student->id], $scope->id))->handle(app(EventAnalyticsCache::class));

        $record = AttendanceRecord::firstOrFail();
        $this->assertSame('late', $record->society_status);
        $this->assertSame('late', $record->competition_status);
    }

    public function test_participant_scope_late_cutoff_applies_when_event_cutoff_is_unset(): void
    {
        [$organizerOrg,, $college] = $this->createOrganizationWithScanner();

        $participantOrg = Organization::create([
            'college_id' => $college->id,
            'name' => 'Fallback Society',
            'acronym' => 'FALL',
            'type' => 'society',
        ]);

        $student = User::create([
            'name' => 'Fallback Student',
            'email' => 'fallback.student@usm.edu.ph',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'student_id_number' => '26-1002',
            'qr_code_value' => 'QR-FALLBACK-CUTOFF',
            'program_code' => 'BSIS',
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $organizerOrg->id,
            'title' => 'Scope Cutoff Event',
            'status' => 'approved',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'event_late_cutoff_min' => null,
        ]);

        $day = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::today(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $scope = $event->participationScopes()->create([
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participantOrg->id,
            'college_id' => $college->id,
            'participant_name_snapshot' => 'FALL - Fallback Society',
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'late_cutoff_min' => 10,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        RawScan::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'event_day_id' => $day->id,
            'student_id' => $student->id,
            'scan_type' => 'time_in',
            'scanned_at' => Carbon::today()->setTime(8, 20),
            'server_received_at' => now(),
            'device_id' => 'policy-test-device',
            'manual_entry' => false,
            'dedup_key' => 'policy-scope-fallback',
        ]);

        (new ResolveAttendanceJob($event->id, [$student->id], $scope->id))->handle(app(EventAnalyticsCache::class));

        $record = AttendanceRecord::firstOrFail();
        $this->assertSame('late_cutoff', $record->society_status);
        $this->assertSame('late_cutoff', $record->competition_status);
    }

    private function createOrganizationWithScanner(
        string $collegeName = 'College of Engineering and Information Technology',
        string $collegeCode = 'CEIT',
        string $organizationName = 'Philippine Society of Information Technology Students',
        string $organizationAcronym = 'PSITS'
    ): array {
        $university = University::firstOrCreate([
            'domain' => 'usm.edu.ph',
        ], [
            'name' => 'University of Southern Mindanao',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => $collegeName,
            'code' => $collegeCode,
        ]);
        $department = Department::create([
            'college_id' => $college->id,
            'name' => "{$collegeName} Academic Programs",
            'code' => 'AP',
            'status' => 'active',
        ]);
        $org = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => $organizationName,
            'acronym' => $organizationAcronym,
            'type' => 'society',
        ]);

        $scanner = User::create([
            'name' => "{$organizationAcronym} Officer",
            'email' => strtolower($organizationAcronym).'.officer@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);
        $this->assignAdminScope($scanner, 'Society Admin', $org, $college);
        $scanner->assignRole('Scanner');

        return [$org, $scanner, $college, $university];
    }

    private function createProgramForCollege(
        College $college,
        string $name = 'BS Information Systems',
        string $code = 'BSIS'
    ): Program {
        $department = Department::where('college_id', $college->id)->oldest()->first()
            ?? Department::create([
                'college_id' => $college->id,
                'name' => "{$college->name} Academic Programs",
                'code' => 'AP',
                'status' => 'active',
            ]);

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $name]
        );
    }

    private function assignAdminScope(
        User $user,
        string $roleName,
        ?Organization $organization,
        ?College $college = null
    ): void {
        $college ??= $organization?->college;
        $existingPrimary = AdminAssignment::query()
            ->where('role_name', $roleName)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('organization_id', $organization?->id)
            ->where('college_id', $college?->id ?: $organization?->college_id)
            ->exists();

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $roleName,
            organization: $organization,
            college: $college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Test assignment',
            approvedBy: $user,
            isPrimaryAdmin: ! $existingPrimary
        );

        if ($organization && $roleName !== 'Super Admin (OSA)' && $organization->type !== 'hr_unit') {
            $this->ensureWorkflowApprovers($organization, $user);
        }
    }

    private function ensureWorkflowApprovers(Organization $organization, User $president): void
    {
        $schoolYear = SchoolYear::firstOrCreate(
            ['label' => '2026-2027'],
            [
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'status' => SchoolYear::STATUS_ACTIVE,
                'activated_at' => now(),
            ],
        );

        $organization->loadMissing(['college', 'department']);
        $department = $organization->department;

        if (! OrganizationOfficerRoster::where('organization_id', $organization->id)->where('school_year_id', $schoolYear->id)->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)->exists()) {
            $secretary = User::factory()->create([
                'college_id' => $organization->college_id,
                'department_id' => $department?->id,
                'email' => 'secretary.'.uniqid().'@usm.edu.ph',
            ]);
            $adviser = User::factory()->create([
                'college_id' => $organization->college_id,
                'department_id' => $department?->id,
                'user_type' => 'faculty',
                'email' => 'adviser.'.uniqid().'@usm.edu.ph',
            ]);

            $roster = OrganizationOfficerRoster::create([
                'organization_id' => $organization->id,
                'school_year_id' => $schoolYear->id,
                'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
                'submitted_by' => $president->id,
                'submitted_at' => now()->subDays(4),
                'verified_by' => $adviser->id,
                'verified_at' => now()->subDays(3),
            ]);

            foreach ([
                [$president, OrganizationOfficer::POSITION_PRESIDENT, 'President'],
                [$secretary, OrganizationOfficer::POSITION_SECRETARY, 'Secretary'],
                [$adviser, OrganizationOfficer::POSITION_ADVISER, 'Faculty Adviser'],
            ] as [$user, $positionKey, $positionTitle]) {
                OrganizationOfficer::create([
                    'officer_roster_id' => $roster->id,
                    'user_id' => $user->id,
                    'position_key' => $positionKey,
                    'position_title' => $positionTitle,
                    'status' => OrganizationOfficer::STATUS_ACTIVE,
                    'effective_start' => $schoolYear->starts_on,
                ]);
            }
        }

        if ($department) {
            $chair = User::factory()->create([
                'college_id' => $organization->college_id,
                'department_id' => $department->id,
                'user_type' => 'faculty',
                'email' => 'chair.'.uniqid().'@usm.edu.ph',
            ]);
            $this->institutionalAssignment($chair, 'Department Chairperson', 'Chairperson', $schoolYear, $organization->college, $department);
        }

        if ($organization->college) {
            $dean = User::factory()->create([
                'college_id' => $organization->college_id,
                'user_type' => 'faculty',
                'email' => 'dean.'.uniqid().'@usm.edu.ph',
            ]);
            $this->institutionalAssignment($dean, 'College Dean', 'Dean', $schoolYear, $organization->college);
        }

        if (! User::role('Chief Security')->exists()) {
            $security = User::factory()->create(['email' => 'security.'.uniqid().'@usm.edu.ph']);
            $security->assignRole('Chief Security');
            $this->institutionalAssignment($security, 'Chief Security', 'Chief Security Officer', $schoolYear);
        }

        if (! User::role('Super Admin (OSA)')->exists()) {
            $osa = User::factory()->create(['email' => 'osa.'.uniqid().'@usm.edu.ph']);
            $osa->assignRole('Super Admin (OSA)');
            $this->institutionalAssignment($osa, 'Super Admin (OSA)', 'OSA Workflow Approver', $schoolYear);
        }
    }

    private function institutionalAssignment(
        User $user,
        string $roleName,
        string $positionTitle,
        SchoolYear $schoolYear,
        ?College $college = null,
        ?Department $department = null,
    ): void {
        if (AdminAssignment::where('role_name', $roleName)
            ->where('academic_year', $schoolYear->label)
            ->where('college_id', $college?->id)
            ->where('department_id', $department?->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->exists()) {
            return;
        }

        $isPrimary = ! AdminAssignment::where('role_name', $roleName)
            ->where('academic_year', $schoolYear->label)
            ->where('college_id', $college?->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('is_primary_admin', true)
            ->exists();

        AdminAssignment::create([
            'user_id' => $user->id,
            'role_name' => $roleName,
            'position_title' => $positionTitle,
            'college_id' => $college?->id,
            'department_id' => $department?->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => $isPrimary,
            'approved_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $steps
     */
    private function createWorkflowTemplateForOrganizationType(string $organizationType, array $steps): void
    {
        $template = ProposalProcessTemplate::create([
            'name' => 'Workflow for '.$organizationType.' '.uniqid(),
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);
        $version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        foreach ($steps as $index => [$stepKey, $title, $role, $scope]) {
            ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => $stepKey,
                'title' => $title,
                'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                'phase' => 'approval',
                'display_order' => $index + 1,
                'is_required' => true,
                'condition_config' => [
                    'assigned_role' => $role,
                    'assigned_scope' => $scope,
                ],
            ]);
        }

        ProposalTemplateApplicabilityRule::create([
            'template_version_id' => $version->id,
            'priority' => 100,
            'organization_type' => $organizationType,
            'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
        ]);

        $version->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function scannerTokenForEvent(Event $event, User $scanner, ?string $label = null): string
    {
        [, $token] = app(ScannerSessionService::class)->createSession(
            event: $event,
            scannerUser: $scanner,
            issuedBy: $scanner,
            label: $label,
            revokeExisting: false
        );

        return $token;
    }

    private function createApprovedEvent(Organization $org, ?Carbon $date = null): Event
    {
        $date ??= Carbon::today();
        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Approved Scanner Event',
            'status' => 'approved',
            'start_date' => $date,
            'end_date' => $date,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => $date,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiProposalPayload(string $title): array
    {
        return [
            'title' => $title,
            'proposal_category' => 'activity',
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => 'University Auditorium',
            'target_demographics' => [],
            'budget_allocations' => [],
            'event_days' => [[
                'day_number' => 1,
                'date' => Carbon::today()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => EvaluationQuestions::defaultSchema(),
        ];
    }
}
