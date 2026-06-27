<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\PendingStudentLink;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Jobs\AnalyzeEventSentimentsJob;
use App\Services\GeminiService;
use App\Services\Contracts\AiServiceInterface;
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
        Role::firstOrCreate(['name' => 'ARO Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'view-own-analytics']);
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
            'organization_id' => $org?->id,
        ]);
        $user->assignRole($role);
        return $user;
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
        $this->get('/portal')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertStatus(200)
            ->assertSee('Sign in with Google')
            ->assertSee('usm.edu.ph');
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
                'student_id_number' => '2023-00100',
                'qr_code_value' => 'QR-UNIQUE-001',
            ])
            ->assertRedirect(route('portal.profile'))
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertEquals('BSIS', $student->program_code);
        $this->assertEquals('2023-00100', $student->student_id_number);
        $this->assertEquals('QR-UNIQUE-001', $student->qr_code_value);
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
            ->assertSee('D1:');
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

    public function test_scanner_link_generation(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Link Test Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-link', $event->id))
            ->assertSessionHas('success')
            ->assertSessionHas('scanner_link');
    }

    public function test_scanner_link_rejected_for_draft(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Draft Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-link', $event->id))
            ->assertSessionHas('error');
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
            ->assertRedirect(route('dashboard.admin-users'))
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
        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Own Event',
            'status' => 'draft',
            'start_date' => today(),
            'end_date' => today(),
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
        $response->assertDontSee('Other Event');
    }
}
