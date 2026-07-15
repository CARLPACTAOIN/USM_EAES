<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AdminAssignment;
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
use App\Models\ScannerSession;
use App\Models\University;
use App\Models\User;
use App\Jobs\AnalyzeEventSentimentsJob;
use App\Services\AdminAssignmentService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use App\Services\GeminiService;
use App\Services\ParticipationScopeService;
use App\Services\Contracts\AiServiceInterface;
use App\Support\EventExportData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WebPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    /**
     * Seed required roles and permissions for testing.
     */
    private function seedRolesAndPermissions(): void
    {
        $permissions = [
            'manage-organizations', 'approve-proposals', 'view-all-analytics',
            'force-validate-any', 'manage-global-settings', 'create-proposals',
            'assign-scanners-own', 'set-late-threshold', 'view-own-analytics',
            'view-college-analytics', 'view-constituent-data', 'force-validate-own',
            'scan-qr-codes', 'manual-entry-id', 'register-profile',
            'view-own-history', 'submit-evaluations',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'Super Admin (OSA)', 'guard_name' => 'web'])
            ->syncPermissions(['manage-organizations', 'approve-proposals', 'view-all-analytics', 'force-validate-any', 'manage-global-settings']);
        Role::firstOrCreate(['name' => 'Society Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'set-late-threshold', 'view-own-analytics', 'force-validate-own']);
        Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'])
            ->syncPermissions(['register-profile', 'view-own-history', 'submit-evaluations']);
        Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'view-own-analytics']);
        Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'view-college-analytics', 'view-constituent-data']);
    }

    private function createHierarchy(): array
    {
        $uni = University::create(['name' => 'USM', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $uni->id, 'name' => 'CEIT', 'code' => 'CEIT']);
        $org = Organization::create(['college_id' => $college->id, 'name' => 'PSITS', 'acronym' => 'PSITS', 'type' => 'society']);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS'
        ]);
        $org->programs()->attach($program->id);
        return compact('uni', 'college', 'org', 'program');
    }

    private function createStudent(Organization $org): User
    {
        $program = Program::where('code', 'BSIS')->first() ?? Program::create([
            'college_id' => $org->college_id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS'
        ]);

        $user = User::create([
            'name' => 'Test Student',
            'email' => 'student' . uniqid() . '@usm.edu.ph',
            'password' => null,
            'organization_id' => $org->id,
            'college_id' => $org->college_id,
            'program_id' => $program->id,
            'program_code' => $program->code,
            'year_level' => '3rd_year',
        ]);
        $user->assignRole('Student');
        return $user;
    }

    private function createAdmin(string $role, ?Organization $org = null): User
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin' . uniqid() . '@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);

        if ($role === 'Student') {
            $user->assignRole('Student');
            return $user;
        }

        $assignmentOrganization = $role === 'Super Admin (OSA)' ? null : $org;
        $assignmentCollege = $assignmentOrganization?->college;

        $existingPrimary = AdminAssignment::query()
            ->where('role_name', $role)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('organization_id', $assignmentOrganization?->id)
            ->where('college_id', $assignmentCollege?->id ?: $assignmentOrganization?->college_id)
            ->exists();

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $role,
            organization: $assignmentOrganization,
            college: $assignmentCollege,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Test assignment',
            approvedBy: $user,
            isPrimaryAdmin: !$existingPrimary
        );

        return $user;
    }

    private function createParticipantScope(Event $event, Organization $participantOrg, string $ppaStatus = EventParticipationScope::PPA_DRAFT): EventParticipationScope
    {
        $parentScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        return EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $parentScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participantOrg->id,
            'college_id' => $participantOrg->college_id,
            'participant_name_snapshot' => $participantOrg->name,
            'ppa_required' => true,
            'ppa_status' => $ppaStatus,
            'hardcopy_submitted' => in_array($ppaStatus, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW, EventParticipationScope::PPA_APPROVED], true),
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'approved_at' => $ppaStatus === EventParticipationScope::PPA_APPROVED ? now() : null,
        ]);
    }

    private function createExportableEvent(Organization $org, User $student): Event
    {
        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Exportable Event',
            'proposal_category' => 'activity',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
            'location_type' => 'on-campus',
            'location_details' => 'USM Auditorium',
            'target_demographics' => [
                'implementing_office' => 'PSITS',
                'target_participants' => 'BSIS students',
            ],
            'budget_allocations' => [
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 1500,
            ],
            'proposal_document_path' => 'proposal-documents/exportable.docx',
            'proposal_document_original_name' => 'exportable.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now()->subDays(2),
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);

        $eventDay = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->subDay(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_day_id' => $eventDay->id,
            'student_id' => $student->id,
            'time_in' => Carbon::parse(today()->subDay()->toDateString() . ' 07:55:00'),
            'time_out' => Carbon::parse(today()->subDay()->toDateString() . ' 12:05:00'),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);

        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => [
                'attainment_of_objectives' => 5,
                'speaker_mastery' => 4,
                'venue_comfort' => 4,
            ],
            'open_comment' => 'Clear and useful activity.',
            'sentiment' => 'positive',
            'sentiment_score' => 0.84,
            'submitted_at' => now()->subHours(3),
        ]);

        return $event;
    }

    // ─── Auth Tests ────────────────────────────────────────

    public function test_unauthenticated_redirect_to_login(): void
    {
        $this->get('/portal')->assertRedirect('/');
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Continue with USM Google Account')
            ->assertSee('Event Attendance & Evaluation System', false)
            ->assertSee('usm.edu.ph');

        $this->get('/login')->assertRedirect('/');
    }

    public function test_student_cannot_access_dashboard(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertStatus(403);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Super Admin (OSA)');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertStatus(200);
    }

    public function test_osa_admin_is_not_given_student_portal_access(): void
    {
        $admin = $this->createAdmin('Super Admin (OSA)');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Student Portal');

        $this->actingAs($admin)
            ->get(route('portal'))
            ->assertStatus(403);
    }

    // ─── Profile Tests ─────────────────────────────────────

    public function test_profile_registration_saves(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->post(route('portal.profile.update'), [
                'college_id' => $h['college']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => '2023-00100',
                'qr_code_value' => 'QR-UNIQUE-001',
            ])
            ->assertRedirect(route('portal.profile'))
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertEquals('BSIS', $student->program_code);
        $this->assertEquals('3rd_year', $student->year_level);
        $this->assertEquals('2023-00100', $student->student_id_number);
        $this->assertEquals('QR-UNIQUE-001', $student->qr_code_value);
        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $student->id,
            'organization_id' => $h['org']->id,
            'college_id' => $h['college']->id,
            'program_id' => $h['program']->id,
            'year_level' => '3rd_year',
            'membership_type' => 'primary_academic',
            'source' => 'profile',
            'status' => OrganizationMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_profile_registration_requires_valid_year_level(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $payload = [
            'college_id' => $h['college']->id,
            'program_id' => $h['program']->id,
            'organization_id' => $h['org']->id,
            'student_id_number' => '2023-00101',
            'qr_code_value' => 'QR-UNIQUE-YEAR',
        ];

        $this->actingAs($student)
            ->post(route('portal.profile.update'), $payload)
            ->assertSessionHasErrors('year_level');

        $this->actingAs($student)
            ->post(route('portal.profile.update'), $payload + ['year_level' => 'senior'])
            ->assertSessionHasErrors('year_level');
    }

    public function test_duplicate_qr_code_rejected(): void
    {
        $h = $this->createHierarchy();
        $existing = $this->createStudent($h['org']);
        $existing->update(['qr_code_value' => 'QR-TAKEN']);

        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->post(route('portal.profile.update'), [
                'college_id' => $h['college']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => '2023-00200',
                'qr_code_value' => 'QR-TAKEN',
            ])
            ->assertSessionHasErrors('qr_code_value');
    }

    public function test_duplicate_student_id_rejected(): void
    {
        $h = $this->createHierarchy();
        $existing = $this->createStudent($h['org']);
        $existing->update(['student_id_number' => 'ID-TAKEN']);

        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->post(route('portal.profile.update'), [
                'college_id' => $h['college']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => 'ID-TAKEN',
                'qr_code_value' => 'QR-UNIQUE-002',
            ])
            ->assertSessionHasErrors('student_id_number');
    }

    // ─── Evaluation Tests ──────────────────────────────────

    public function test_evaluation_submission(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Test Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $eventDay = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        // Create attendance record for the student
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_day_id' => $eventDay->id,
            'student_id' => $student->id,
            'time_in' => now(),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => false,
        ]);

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'attainment_of_objectives' => 4,
                    'speaker_mastery' => 5,
                    'venue_comfort' => 3,
                ],
                'open_comment' => 'Great event!',
            ])
            ->assertRedirect(route('portal'))
            ->assertSessionHas('success');

        // Verify evaluation created
        $this->assertDatabaseHas('evaluations', [
            'event_id' => $event->id,
            'student_id' => $student->id,
        ]);

        // Verify evaluation gate toggled validity
        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $student->id,
            'valid' => true,
        ]);
    }

    public function test_closed_evaluation_window(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Past Event',
            'status' => 'completed',
            'start_date' => today()->subDays(5),
            'end_date' => today()->subDays(5),
        ]);

        $eventDay = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->subDays(5),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_day_id' => $eventDay->id,
            'student_id' => $student->id,
            'time_in' => now()->subDays(5),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => false,
        ]);

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'attainment_of_objectives' => 4,
                    'speaker_mastery' => 5,
                    'venue_comfort' => 3,
                ],
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('evaluations', [
            'event_id' => $event->id,
            'student_id' => $student->id,
        ]);
    }

    // ─── Event PPA Tests ───────────────────────────────────

    public function test_event_status_transitions(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        // Create event as Society Admin
        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Status Test Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/status-test.docx',
            'proposal_document_original_name' => 'status-test.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);

        // Submit
        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $event->id))
            ->assertSessionHas('success');
        $event->refresh();
        $this->assertEquals('submitted', $event->status);

        // Review (OSA)
        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.review', $event->id))
            ->assertSessionHas('success');
        $event->refresh();
        $this->assertEquals('under_review', $event->status);

        // Approve (OSA)
        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.approve', $event->id))
            ->assertSessionHas('success');
        $event->refresh();
        $this->assertEquals('approved', $event->status);
    }

    public function test_osa_event_list_hides_organizer_drafts(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Organizer Private Draft',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Submitted For OSA',
            'status' => 'submitted',
            'start_date' => today()->addDays(8),
            'end_date' => today()->addDays(8),
        ]);

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Submitted For OSA')
            ->assertDontSee('Organizer Private Draft')
            ->assertDontSee('New Event')
            ->assertDontSee('>Draft<', false);

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events', ['status' => 'draft']))
            ->assertOk()
            ->assertDontSee('Organizer Private Draft');
    }

    public function test_osa_can_see_submitted_review_and_history_proposals(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        foreach (['submitted', 'under_review', 'approved', 'rejected', 'completed'] as $status) {
            Event::create([
                'organization_id' => $h['org']->id,
                'title' => 'Visible ' . ucwords(str_replace('_', ' ', $status)),
                'status' => $status,
                'start_date' => today()->addDays(7),
                'end_date' => today()->addDays(7),
            ]);
        }

        $response = $this->actingAs($osaAdmin)
            ->get(route('dashboard.events'))
            ->assertOk();

        foreach (['Submitted', 'Under Review', 'Approved', 'Rejected', 'Completed'] as $label) {
            $response->assertSee('Visible ' . $label);
        }
    }

    public function test_osa_cannot_create_or_submit_organizer_drafts(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $draft = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Draft Not For OSA Submit',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/draft.docx',
            'proposal_document_original_name' => 'draft.docx',
            'hardcopy_submitted' => true,
        ]);

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.create'), [])
            ->assertForbidden();

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.submit', $draft->id))
            ->assertForbidden();

        $draft->refresh();
        $this->assertSame('draft', $draft->status);
    }

    public function test_society_admin_can_still_view_and_submit_own_draft(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $draft = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Society Private Draft',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/society-draft.docx',
            'proposal_document_original_name' => 'society-draft.docx',
            'hardcopy_submitted' => true,
        ]);

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Society Private Draft')
            ->assertSee('New Event')
            ->assertSee('Draft');

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $draft->id))
            ->assertSessionHas('success');

        $draft->refresh();
        $this->assertSame('submitted', $draft->status);
    }

    public function test_osa_cannot_directly_download_or_export_draft_proposal(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $draft = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Direct Draft Access',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/direct-draft.docx',
            'proposal_document_original_name' => 'direct-draft.docx',
        ]);

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events.proposal-document', $draft->id))
            ->assertForbidden();

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events.exports.attendance.excel', $draft))
            ->assertForbidden();
    }

    public function test_osa_event_list_includes_proposal_detail_metadata(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Metadata Review Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'location_type' => 'on-campus',
            'location_details' => 'USM Gymnasium',
            'target_demographics' => [
                'implementing_office' => 'PSITS',
                'collaborating_office' => 'Guidance Office',
                'target_participants' => 'All BSIS students',
            ],
            'budget_allocations' => [
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 2500,
            ],
            'proposal_document_path' => 'proposal-documents/metadata.docx',
            'proposal_document_original_name' => 'metadata.docx',
            'resolution_number' => 'RES-2026-001',
            'hardcopy_submitted' => true,
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addDays(7),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('data-detail-trigger', false)
            ->assertSee('USM Gymnasium')
            ->assertSee('Guidance Office')
            ->assertSee('All BSIS students')
            ->assertSee('Organization fund')
            ->assertSee('PHP 2,500.00')
            ->assertSee('RES-2026-001')
            ->assertSee('metadata.docx')
            ->assertSee('data-detail-start-time="8:00 AM"', false)
            ->assertSee('data-detail-end-time="12:00 PM"', false)
            ->assertSee('Start time')
            ->assertSee('End time')
            ->assertSee('D1:')
            ->assertSee('8:00 AM - 12:00 PM');
    }

    public function test_event_creation_requires_proposal_document_and_tracks_hardcopy(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $basePayload = [
            'title' => 'Documented PPA Event',
            'proposal_category' => 'activity',
            'start_date' => today()->addDays(10)->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => 'USM Gymnasium',
            'implementing_office' => 'PSITS',
            'target_participants' => 'All BSIS students',
            'source_of_fund' => 'Organization fund',
            'budget_cost' => 2500,
            'resolution_number' => 'RES-2026-001',
            'hardcopy_submitted' => '1',
            'head_organization_signed' => '1',
            'adviser_signed' => '1',
            'event_days' => [[
                'date' => today()->addDays(10)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
        ];

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), $basePayload)
            ->assertSessionHasErrors('proposal_document');

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), array_merge($basePayload, [
                'proposal_document' => UploadedFile::fake()->create('official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]))
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('success');

        $event = Event::where('title', 'Documented PPA Event')->first();
        $this->assertNotNull($event);
        $this->assertSame('activity', $event->proposal_category);
        $this->assertTrue($event->hardcopy_submitted);
        $this->assertTrue($event->head_organization_signed);
        $this->assertTrue($event->adviser_signed);
        $this->assertSame('official-ppa.docx', $event->proposal_document_original_name);
        Storage::disk('local')->assertExists($event->proposal_document_path);
    }

    public function test_draft_without_hardcopy_cannot_be_submitted(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Softcopy Only Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/softcopy-only.docx',
            'proposal_document_original_name' => 'softcopy-only.docx',
            'hardcopy_submitted' => false,
        ]);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $event->id))
            ->assertSessionHas('error');

        $event->refresh();
        $this->assertSame('draft', $event->status);
    }

    public function test_participating_events_tab_shows_invited_org_after_parent_submission(): void
    {
        $h = $this->createHierarchy();
        $this->createAdmin('Society Admin', $h['org']);

        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Partner Society',
            'acronym' => 'PARTNER',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'PSITS Shared Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(8),
            'end_date' => today()->addDays(8),
        ]);
        $this->createParticipantScope($event, $partnerOrg);

        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events', ['workspace' => 'participating']))
            ->assertOk()
            ->assertDontSee('PSITS Shared Event');

        $event->update(['status' => 'submitted']);

        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events', ['workspace' => 'participating']))
            ->assertOk()
            ->assertSee('Participating Events')
            ->assertSee('PSITS Shared Event')
            ->assertSee('Partner Society')
            ->assertSee('Your participant PPA')
            ->assertSee('Submit PPA')
            ->assertDontSee('Add Participant Scope');

        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertDontSee('PSITS Shared Event');
    }

    public function test_osa_event_page_is_review_only_for_participant_scopes_and_scanners(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $scanner = $this->createStudent($h['org']);
        $scanner->update([
            'student_id_number' => '2024-05001',
            'qr_code_value' => 'QR-OSA-BOUNDARY-SCANNER',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'OSA Review Only Runtime Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $assignment = EventScannerAssignment::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'audience_scope_type' => $scope->participant_type,
            'scanner_user_id' => $scanner->id,
            'assigned_by_user_id' => $organizerAdmin->id,
            'label' => 'Main Gate',
            'status' => EventScannerAssignment::STATUS_ACTIVE,
            'max_devices' => 1,
        ]);

        $session = ScannerSession::create([
            'event_id' => $event->id,
            'assignment_id' => $assignment->id,
            'event_participation_scope_id' => $scope->id,
            'scanner_user_id' => $scanner->id,
            'issued_by' => $organizerAdmin->id,
            'label' => 'Main Gate Device',
            'status' => ScannerSession::STATUS_ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($osaAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('OSA Review Only Runtime Event')
            ->assertSee('Scope Register')
            ->assertDontSee('>Scanners', false)
            ->assertDontSee('Add Participant Scope')
            ->assertDontSee('data-scanner-search-url', false)
            ->assertDontSee('name="scanner_user_ids[]"', false)
            ->assertDontSee(route('dashboard.events.scanner-assignments.store', $event->id), false)
            ->assertDontSee(route('dashboard.events.scanner-assignments.revoke', [$event->id, $assignment->id]), false)
            ->assertDontSee(route('dashboard.events.scanner-sessions.revoke', [$event->id, $session->id]), false)
            ->assertDontSee('Revoke assignment')
            ->assertDontSee('>Revoke<', false);

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $event->id), [
                'organization_id' => $h['org']->id,
                'capture_mode' => 'self_scanning',
            ])
            ->assertForbidden();

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $scanner->id,
                'label' => 'OSA should not assign',
            ])
            ->assertForbidden();

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.scanner-assignments.revoke', [$event->id, $assignment->id]), [
                'reason' => 'OSA should not revoke assignment.',
            ])
            ->assertForbidden();

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.events.scanner-sessions.revoke', [$event->id, $session->id]), [
                'reason' => 'OSA should not revoke session.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('event_scanner_assignments', [
            'id' => $assignment->id,
            'status' => EventScannerAssignment::STATUS_ACTIVE,
            'revoked_by_user_id' => null,
        ]);
        $this->assertDatabaseHas('scanner_sessions', [
            'id' => $session->id,
            'status' => ScannerSession::STATUS_ACTIVE,
            'revoked_by' => null,
        ]);
    }

    public function test_participant_audience_builder_renders_batch_controls(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Audience Builder Render Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs($organizerAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('All can participate')
            ->assertSee('College')
            ->assertSee('Specific org')
            ->assertSee('name="audience_mode"', false)
            ->assertSee('name="college_ids[]"', false)
            ->assertSee('name="organization_ids[]"', false)
            ->assertSee('Search College name or code')
            ->assertDontSee('Participant organization</option>', false);
    }

    public function test_participant_audience_builder_creates_org_college_and_all_scopes(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);

        $otherCollege = College::create([
            'university_id' => $h['uni']->id,
            'name' => 'College of Arts and Sciences',
            'code' => 'CAS',
        ]);
        $otherProgram = Program::create([
            'college_id' => $otherCollege->id,
            'name' => 'BS Biology',
            'code' => 'BSBIO',
        ]);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Audience Partner Society',
            'acronym' => 'APS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $otherOrg = Organization::create([
            'college_id' => $otherCollege->id,
            'name' => 'Biology Society',
            'acronym' => 'BIOSOC',
            'type' => 'society',
        ]);
        $otherOrg->programs()->attach($otherProgram->id);
        $lsgOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'CEIT Local Student Government',
            'acronym' => 'CEIT-LSG',
            'type' => 'lsg',
        ]);
        $lsgAdmin = $this->createAdmin('LSG Admin', $lsgOrg);
        $collegeStudent = $this->createStudent($partnerOrg);

        $orgEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Organization Batch Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $orgEvent->id), [
                'audience_mode' => 'organization',
                'organization_ids' => [$partnerOrg->id, $otherOrg->id, $h['org']->id],
                'capture_mode' => 'self_scanning',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $orgEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'organization_id' => $partnerOrg->id,
            'participant_type' => 'society',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $orgEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'organization_id' => $otherOrg->id,
            'participant_type' => 'society',
        ]);
        $this->assertDatabaseMissing('event_participation_scopes', [
            'event_id' => $orgEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'organization_id' => $h['org']->id,
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $orgEvent->id), [
                'audience_mode' => 'organization',
                'organization_ids' => [$partnerOrg->id],
                'capture_mode' => 'self_scanning',
            ])
            ->assertSessionHas('error', 'Every selected audience already has an active participant scope for the event.');

        $collegeEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'College Batch Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(6),
            'end_date' => today()->addDays(6),
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $collegeEvent->id), [
                'audience_mode' => 'college',
                'college_ids' => [$h['college']->id, $otherCollege->id],
                'capture_mode' => 'central_gate',
                'ppa_required' => '0',
                'analytics_rollup_enabled' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $collegeEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'college',
            'organization_id' => null,
            'college_id' => $h['college']->id,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'capture_mode' => 'central_gate',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $collegeEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'college',
            'organization_id' => null,
            'college_id' => $otherCollege->id,
        ]);

        $collegeScope = EventParticipationScope::query()
            ->where('event_id', $collegeEvent->id)
            ->where('participant_type', 'college')
            ->where('college_id', $h['college']->id)
            ->firstOrFail();
        $this->assertTrue(app(ParticipationScopeService::class)->userBelongsToScope($collegeStudent, $collegeScope));
        $this->assertNotNull($lsgAdmin->fresh()->notifications()->first());

        $allEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'All Colleges Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);
        $expectedCollegeScopes = College::count();

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $allEvent->id), [
                'audience_mode' => 'all',
                'capture_mode' => 'central_gate',
                'ppa_required' => '0',
            ])
            ->assertSessionHas('success');

        $this->assertSame($expectedCollegeScopes, EventParticipationScope::query()
            ->where('event_id', $allEvent->id)
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('participant_type', 'college')
            ->whereNull('organization_id')
            ->count());
    }

    public function test_invited_org_can_submit_only_own_participant_ppa(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Partner Society',
            'acronym' => 'PARTNER',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other Participant',
            'acronym' => 'OTHERP',
            'type' => 'society',
        ]);
        $otherOrg->programs()->attach($h['program']->id);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'PSITS Multi-Org Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(8),
            'end_date' => today()->addDays(8),
        ]);
        $ownScope = $this->createParticipantScope($event, $partnerOrg);
        $otherScope = $this->createParticipantScope($event, $otherOrg);

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.submit', [$event->id, $ownScope->id]), [
                'ppa_softcopy' => UploadedFile::fake()->create('partner-ppa.pdf', 128, 'application/pdf'),
                'hardcopy_submitted' => '1',
                'org_head_signed' => '1',
                'adviser_signed' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $ownScope->id,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'submitted_by' => $partnerAdmin->id,
        ]);

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.submit', [$event->id, $otherScope->id]), [
                'ppa_softcopy' => UploadedFile::fake()->create('other-ppa.pdf', 128, 'application/pdf'),
                'hardcopy_submitted' => '1',
                'org_head_signed' => '1',
                'adviser_signed' => '1',
            ])
            ->assertForbidden();
    }

    public function test_participant_scope_invitation_creates_in_app_notification_for_invited_admin(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Notification Partner Society',
            'acronym' => 'NPS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Visible Invitation Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $event->id), [
                'organization_id' => $partnerOrg->id,
                'capture_mode' => 'self_scanning',
            ])
            ->assertSessionHas('success');

        $notification = $partnerAdmin->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('participant_scope_invitation', $notification->data['kind']);
        $this->assertSame('Visible Invitation Event', $notification->data['event_title']);

        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.notifications'))
            ->assertOk()
            ->assertSee('Participant invitation needs acknowledgement')
            ->assertSee('Visible Invitation Event')
            ->assertSee('Needs action');
    }

    public function test_draft_participant_scope_notifications_wait_until_parent_submission(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Deferred Partner Society',
            'acronym' => 'DPS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Deferred Invitation Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'proposal_document_path' => 'proposal-documents/deferred.pdf',
            'hardcopy_submitted' => true,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.store', $event->id), [
                'organization_id' => $partnerOrg->id,
                'capture_mode' => 'self_scanning',
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, $partnerAdmin->fresh()->notifications()->count());

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.submit', $event->id))
            ->assertSessionHas('success');

        $notification = $partnerAdmin->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('participant_scope_invitation', $notification->data['kind']);
        $this->assertSame('Deferred Invitation Event', $notification->data['event_title']);
    }

    public function test_invited_admin_can_acknowledge_invitation_and_organizer_gets_receipt(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Acknowledging Partner Society',
            'acronym' => 'APS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Acknowledgement Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.acknowledge', [$event->id, $scope->id]))
            ->assertRedirect(route('dashboard.events', ['workspace' => 'participating']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'invitation_acknowledged_by' => $partnerAdmin->id,
        ]);

        $receipt = $organizerAdmin->fresh()->notifications()->first();

        $this->assertNotNull($receipt);
        $this->assertSame('participant_scope_acknowledged', $receipt->data['kind']);
        $this->assertSame('Acknowledgement Event', $receipt->data['event_title']);
    }

    public function test_participant_ppa_submission_notifies_osa_for_review(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $osa = $this->createAdmin('Super Admin (OSA)');
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Review Notification Partner',
            'acronym' => 'RNP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'OSA Review Notification Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.submit', [$event->id, $scope->id]), [
                'ppa_softcopy' => UploadedFile::fake()->create('partner-review-ppa.pdf', 128, 'application/pdf'),
                'hardcopy_submitted' => '1',
                'org_head_signed' => '1',
                'adviser_signed' => '1',
            ])
            ->assertSessionHas('success');

        $notification = $osa->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('participant_scope_submitted', $notification->data['kind']);
        $this->assertTrue($notification->data['needs_action']);
    }

    public function test_participant_scope_reminder_command_is_idempotent_per_day(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Reminder Partner Society',
            'acronym' => 'RPS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Reminder Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $this->artisan('eaes:send-participant-scope-reminders')->assertExitCode(0);
        $this->artisan('eaes:send-participant-scope-reminders')->assertExitCode(0);

        $notifications = $partnerAdmin->fresh()->notifications()
            ->get()
            ->filter(fn ($notification) => ($notification->data['kind'] ?? null) === 'participant_scope_reminder');

        $this->assertCount(1, $notifications);
        $this->assertNotNull($scope->fresh()->invitation_last_reminded_at);
    }

    public function test_invited_org_can_manage_scanners_for_approved_participant_scope(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Partner Society',
            'acronym' => 'PARTNER',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);
        $scanner = $this->createStudent($partnerOrg);
        $scanner->update([
            'student_id_number' => '2024-02001',
            'qr_code_value' => 'QR-PARTNER-SCANNER',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'PSITS Approved Shared Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg, EventParticipationScope::PPA_APPROVED);

        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events', ['workspace' => 'participating']))
            ->assertOk()
            ->assertSee('PSITS Approved Shared Event')
            ->assertSee('Scanners');

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $scanner->id,
                'label' => 'Partner Gate',
            ])
            ->assertSessionHas('success');

        $assignment = EventScannerAssignment::where('event_id', $event->id)
            ->where('event_participation_scope_id', $scope->id)
            ->where('scanner_user_id', $scanner->id)
            ->first();

        $this->assertNotNull($assignment);

        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.scanner-assignments.revoke', [$event->id, $assignment->id]), [
                'reason' => 'Shift changed.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_scanner_assignments', [
            'id' => $assignment->id,
            'status' => EventScannerAssignment::STATUS_REVOKED,
            'revoked_by_user_id' => $partnerAdmin->id,
        ]);
    }

    public function test_admin_assigns_scanner_only_within_scope_for_approved_events(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);
        $student->update([
            'student_id_number' => '2024-01001',
            'qr_code_value' => 'QR-SCANNER-01001',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Scanner Assignment Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $student->id,
                'label' => 'Main Gate',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_scanner_assignments', [
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'scanner_user_id' => $student->id,
            'status' => 'active',
        ]);

        $draft = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Draft Scanner Assignment Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $draftStudent = $this->createStudent($h['org']);
        $draftStudent->update([
            'student_id_number' => '2024-01002',
            'qr_code_value' => 'QR-SCANNER-01002',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $draft->id), [
                'scanner_user_id' => $draftStudent->id,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('event_scanner_assignments', [
            'event_id' => $draft->id,
            'scanner_user_id' => $draftStudent->id,
        ]);

        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other Society',
            'acronym' => 'OTHER',
            'type' => 'society',
        ]);
        $otherOrg->programs()->attach($h['program']->id);
        $otherStudent = $this->createStudent($otherOrg);
        $otherStudent->update([
            'student_id_number' => '2024-01003',
            'qr_code_value' => 'QR-SCANNER-01003',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $otherStudent->id,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('event_scanner_assignments', [
            'event_id' => $event->id,
            'scanner_user_id' => $otherStudent->id,
        ]);
    }

    public function test_admin_can_assign_multiple_scanners_in_one_submission(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $firstScanner = $this->createStudent($h['org']);
        $firstScanner->update([
            'student_id_number' => '2024-04001',
            'qr_code_value' => 'QR-MULTI-SCANNER-1',
        ]);
        $secondScanner = $this->createStudent($h['org']);
        $secondScanner->update([
            'student_id_number' => '2024-04002',
            'qr_code_value' => 'QR-MULTI-SCANNER-2',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Multi Scanner Assignment Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_ids' => [$firstScanner->id, $secondScanner->id],
                'label' => 'Entrance Team',
            ])
            ->assertSessionHas('success', '2 scanners are now assigned for Multi Scanner Assignment Event.');

        foreach ([$firstScanner, $secondScanner] as $scanner) {
            $this->assertDatabaseHas('event_scanner_assignments', [
                'event_id' => $event->id,
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $scanner->id,
                'label' => 'Entrance Team',
                'status' => EventScannerAssignment::STATUS_ACTIVE,
            ]);
        }
    }

    public function test_organizer_scope_includes_owner_without_duplicate_participant_ppa(): void
    {
        $h = $this->createHierarchy();
        $organizerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Organizer Society',
            'acronym' => 'ORGZ',
            'type' => 'society',
        ]);
        $organizerOrg->programs()->attach($h['program']->id);

        $admin = $this->createAdmin('Society Admin', $organizerOrg);
        $student = $this->createStudent($organizerOrg);
        $student->update([
            'student_id_number' => '2024-03001',
            'qr_code_value' => 'QR-ORGANIZER-AUDIENCE',
        ]);

        $event = Event::create([
            'organization_id' => $organizerOrg->id,
            'title' => 'Organizer Owned Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->assertSame(EventParticipationScope::ROLE_ORGANIZER, $scope->participation_role);
        $this->assertTrue(app(ParticipationScopeService::class)->userBelongsToScope($student, $scope));

        $this->actingAs($admin)
            ->post(route('dashboard.events.participant-scopes.store', $event->id), [
                'organization_id' => $organizerOrg->id,
                'capture_mode' => 'self_scanning',
            ])
            ->assertSessionHas('error', 'The organizer is already included through the organizer attendance scope and does not need a participant PPA.');

        $this->assertDatabaseMissing('event_participation_scopes', [
            'event_id' => $event->id,
            'organization_id' => $organizerOrg->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
        ]);
    }

    public function test_scanner_candidate_search_includes_active_organization_memberships(): void
    {
        $h = $this->createHierarchy();
        $organizerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Membership Organizer Society',
            'acronym' => 'MOS',
            'type' => 'society',
        ]);
        $organizerOrg->programs()->attach($h['program']->id);

        $admin = $this->createAdmin('Society Admin', $organizerOrg);

        $scanner = User::create([
            'name' => 'Membership Scanner',
            'email' => 'scanner.membership'.uniqid().'@usm.edu.ph',
            'password' => null,
            'organization_id' => null,
            'college_id' => $h['college']->id,
            'program_id' => $h['program']->id,
            'program_code' => $h['program']->code,
            'student_id_number' => '23-03002',
            'qr_code_value' => 'QR-MEMBERSHIP-SCANNER',
        ]);
        $scanner->assignRole('Student');
        OrganizationMembership::create([
            'user_id' => $scanner->id,
            'organization_id' => $organizerOrg->id,
            'college_id' => $h['college']->id,
            'program_id' => $h['program']->id,
            'membership_type' => 'secondary_org',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'import',
        ]);

        $event = Event::create([
            'organization_id' => $organizerOrg->id,
            'title' => 'Membership Scanner Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->actingAs($admin)
            ->getJson(route('dashboard.events.scanner-candidates', [
                'event' => $event->id,
                'q' => '23',
                'event_participation_scope_id' => $scope->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $scanner->id)
            ->assertJsonPath('data.0.organization', 'MOS');

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $scanner->id,
                'label' => 'Membership Gate',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_scanner_assignments', [
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'scanner_user_id' => $scanner->id,
            'status' => EventScannerAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_dashboard_backfills_missing_organizer_scope_for_scanner_search(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $scanner = $this->createStudent($h['org']);
        $scanner->update([
            'student_id_number' => '2024-03003',
            'qr_code_value' => 'QR-LEGACY-ORGANIZER-SCANNER',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Legacy Organizer Scope Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->assertDatabaseMissing('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Legacy Organizer Scope Event')
            ->assertSee('data-scanner-search-url', false)
            ->assertSee('x-data="scannerAssignmentPicker()"', false)
            ->assertSee('name="scanner_user_ids[]"', false)
            ->assertSee('selectedScanners.length === 0', false)
            ->assertSee('Selected scanners')
            ->assertSee('Clear selection')
            ->assertDontSee('scannerAssignmentPicker(@js', false);

        $scope = EventParticipationScope::where('event_id', $event->id)
            ->where('participation_role', EventParticipationScope::ROLE_ORGANIZER)
            ->first();

        $this->assertNotNull($scope);
        $this->assertSame(EventParticipationScope::PPA_APPROVED, $scope->ppa_status);

        $this->actingAs($admin)
            ->getJson(route('dashboard.events.scanner-candidates', [
                'event' => $event->id,
                'q' => '2024-03003',
                'event_participation_scope_id' => $scope->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $scanner->id);
    }

    public function test_scanner_assignment_session_history_renders_for_dashboard(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);
        $student->update([
            'student_id_number' => '2024-01004',
            'qr_code_value' => 'QR-SCANNER-01004',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Session History Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Gym Entrance'
        );

        [$session] = app(ScannerSessionService::class)->createSession(
            event: $event,
            scannerUser: $student,
            issuedBy: $admin,
            label: 'Gym Entrance',
            assignment: $assignment
        );
        $session->update([
            'device_id' => 'dashboard-history-device',
            'device_fingerprint_hash' => hash('sha256', 'dashboard-history-device'),
            'activated_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.events'))
            ->assertSee('Scanner Assignments')
            ->assertSee('Active Devices / Session History')
            ->assertSee('Gym Entrance')
            ->assertSee($student->name);

        $this->assertDatabaseHas('scanner_sessions', [
            'id' => $session->id,
            'event_id' => $event->id,
            'assignment_id' => $assignment->id,
            'scanner_user_id' => $student->id,
            'status' => ScannerSession::STATUS_ACTIVE,
        ]);
    }

    public function test_event_attendance_pdf_export_downloads_for_scoped_admin(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $event = $this->createExportableEvent($h['org'], $student);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.events.exports.attendance.pdf', $event));

        $response->assertStatus(200);
        $disposition = strtolower($response->headers->get('content-disposition', ''));
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('attendance', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function test_event_evaluations_excel_export_downloads_for_scoped_admin(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $event = $this->createExportableEvent($h['org'], $student);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.events.exports.evaluations.excel', $event));

        $response->assertStatus(200);
        $disposition = strtolower($response->headers->get('content-disposition', ''));
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('evaluations', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    public function test_event_export_payload_includes_year_level_columns(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        $student->update(['year_level' => '4th_year']);
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $event = $this->createExportableEvent($h['org'], $student);

        $payload = EventExportData::build($event, $admin);

        $this->assertSame('Year Level', $payload['attendance_rows'][0][5]);
        $this->assertSame('4th Year', $payload['attendance_rows'][1][5]);
        $this->assertSame('Year Level', $payload['evaluation_rows'][0][3]);
        $this->assertSame('4th Year', $payload['evaluation_rows'][1][3]);
    }

    public function test_organization_analytics_filters_students_by_year_level(): void
    {
        $h = $this->createHierarchy();
        $thirdYear = $this->createStudent($h['org']);
        $fourthYear = $this->createStudent($h['org']);
        $fourthYear->update(['year_level' => '4th_year']);
        $admin = $this->createAdmin('Society Admin', $h['org']);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Year Level Analytics Event',
            'proposal_category' => 'activity',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.analytics', ['year_level' => '3rd_year']))
            ->assertOk()
            ->assertViewHas('selectedYearLevel', '3rd_year')
            ->assertViewHas('orgMetrics', function (array $orgMetrics) use ($h) {
                return ($orgMetrics[$h['org']->id]['total_members'] ?? null) === 1;
            });

        $this->assertSame('3rd_year', $thirdYear->fresh()->year_level);
    }

    public function test_event_export_blocks_cross_tenant_admin(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Different Society',
            'acronym' => 'DS',
            'type' => 'society',
        ]);
        $student = $this->createStudent($otherOrg);
        $event = $this->createExportableEvent($otherOrg, $student);

        $this->actingAs($admin)
            ->get(route('dashboard.events.exports.attendance.excel', $event))
            ->assertStatus(403);
    }

    public function test_osa_can_export_any_event(): void
    {
        $h = $this->createHierarchy();
        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other Society',
            'acronym' => 'OS',
            'type' => 'society',
        ]);
        $student = $this->createStudent($otherOrg);
        $event = $this->createExportableEvent($otherOrg, $student);
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $response = $this->actingAs($osaAdmin)
            ->get(route('dashboard.events.exports.attendance.excel', $event));

        $response->assertStatus(200);
        $this->assertStringContainsString('.xlsx', strtolower($response->headers->get('content-disposition', '')));
    }

    public function test_empty_event_exports_download(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Empty Export Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.events.exports.evaluations.pdf', $event));

        $response->assertStatus(200);
        $this->assertStringContainsString('.pdf', strtolower($response->headers->get('content-disposition', '')));
    }

    public function test_ai_dashboard_renders_for_admin(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $this->actingAs($admin)
            ->get(route('dashboard.ai'))
            ->assertStatus(200)
            ->assertSee('AI Insights')
            ->assertSee('NLP Query Assistant');
    }

    public function test_manual_sentiment_action_dispatches_for_closed_scoped_event(): void
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Closed Sentiment Event',
            'status' => 'completed',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDays(3),
        ]);
        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->subDays(3),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Good facilitation.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.sentiments.analyze', $event))
            ->assertSessionHas('success');

        Bus::assertDispatched(AnalyzeEventSentimentsJob::class, 1);
    }

    public function test_manual_sentiment_action_rejects_open_event(): void
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Open Sentiment Event',
            'status' => 'completed',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '23:00',
        ]);
        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['speaker_mastery' => 5],
            'open_comment' => 'Still accepting evaluations.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.sentiments.analyze', $event))
            ->assertSessionHas('error');

        Bus::assertNotDispatched(AnalyzeEventSentimentsJob::class);
    }

    public function test_manual_sentiment_action_blocks_cross_tenant_admin(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other Society',
            'acronym' => 'OS',
            'type' => 'society',
        ]);

        $event = Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Sentiment Event',
            'status' => 'completed',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDays(3),
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.sentiments.analyze', $event))
            ->assertStatus(403);
    }

    public function test_student_cannot_run_manual_sentiment_action(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Student Blocked Event',
            'status' => 'completed',
            'start_date' => today()->subDays(3),
            'end_date' => today()->subDays(3),
        ]);

        $this->actingAs($student)
            ->post(route('dashboard.events.sentiments.analyze', $event))
            ->assertStatus(403);
    }

    public function test_ai_web_query_is_scoped_like_api_query(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Own AI Event',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);

        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other AI Society',
            'acronym' => 'OAI',
            'type' => 'society',
        ]);
        Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other AI Event',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService {
            public function __construct()
            {
            }

            public function parseNaturalLanguageQuery(string $query, User $user): array
            {
                return [
                    'target_table' => 'events',
                    'filters' => [
                        ['field' => 'title', 'operator' => 'LIKE', 'value' => 'AI Event'],
                        ['field' => 'qr_code_value', 'operator' => '=', 'value' => 'unsafe'],
                    ],
                ];
            }
        });

        $this->actingAs($admin)
            ->post(route('dashboard.ai.query'), ['query' => 'Show AI events'])
            ->assertStatus(200)
            ->assertSee('Own AI Event')
            ->assertDontSee('Other AI Event')
            ->assertSee('qr_code_value');
    }

    // ─── Admin Provisioning Tests ──────────────────────────

    public function test_admin_provisioning(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $this->actingAs($osaAdmin)
            ->post(route('dashboard.admin-users.create'), [
                'name' => 'New Admin',
                'email' => 'newadmin@usm.edu.ph',
                'password' => 'password123',
                'role' => 'Society Admin',
                'organization_id' => $h['org']->id,
            ])
            ->assertRedirect(route('dashboard.admin-users') . '?tab=assignments')
            ->assertSessionHas('success');

        $newUser = User::where('email', 'newadmin@usm.edu.ph')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole('Society Admin'));
    }

    public function test_non_osa_cannot_provision_admins(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.admin-users'))
            ->assertStatus(403);
    }

    // ─── Pending QR Link Tests ─────────────────────────────

    public function test_pending_qr_resolve(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Super Admin (OSA)');
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'QR Test Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $link = PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'qr_code_value' => 'UNKNOWN-QR-123',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.pending-links.resolve', $link->id), [
                'student_id' => $student->id,
                'notes' => 'Manual resolution',
            ])
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertEquals('resolved', $link->status);
        $this->assertEquals($student->id, $link->resolved_student_id);
    }

    public function test_pending_qr_flag(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Flag Test Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $link = PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'qr_code_value' => 'UNKNOWN-QR-456',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.pending-links.flag', $link->id), [
                'notes' => 'Not a student',
            ])
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertEquals('flagged', $link->status);
    }

    // ─── Tenant Scope Tests ────────────────────────────────

    public function test_tenant_scope_events(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        // Create an event in this org
        $ownEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Own Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventDay::create([
            'event_id' => $ownEvent->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        // Create another org + event
        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Other Org',
            'acronym' => 'OO',
            'type' => 'society',
        ]);
        Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Other Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $response = $this->actingAs($societyAdmin)
            ->get(route('dashboard.events'));

        $response->assertStatus(200);
        $response->assertSee('Own Event');
        $response->assertSee('8:00 AM - 12:00 PM');
        $response->assertSee('D1:');
        $response->assertDontSee('Other Event');
    }

    public function test_student_admin_dashboard_scope_comes_from_assignment_not_student_profile(): void
    {
        $h = $this->createHierarchy();
        $studentAdmin = $this->createStudent($h['org']);

        $adminOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Admin Scope Society',
            'acronym' => 'ADMIN',
            'type' => 'society',
        ]);
        $adminOrg->programs()->attach($h['program']->id);

        app(AdminAssignmentService::class)->createAssignment(
            user: $studentAdmin,
            roleName: 'Society Admin',
            organization: $adminOrg,
            college: $h['college'],
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'President',
            approvedBy: $studentAdmin,
            isPrimaryAdmin: true
        );

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Student Home Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        Event::create([
            'organization_id' => $adminOrg->id,
            'title' => 'Assigned Admin Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->actingAs($studentAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Assigned Admin Event')
            ->assertDontSee('Student Home Event');

        $this->actingAs($studentAdmin)
            ->get(route('portal.profile'))
            ->assertOk()
            ->assertSee('PSITS');
    }
}
