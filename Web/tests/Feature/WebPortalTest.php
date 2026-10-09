<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeEventSentimentsJob;
use App\Jobs\ResolveAttendanceJob;
use App\Livewire\Dashboard\AiInsightsQuery;
use App\Livewire\Dashboard\AnalyticsOverviewFilters;
use App\Livewire\Dashboard\EventAttendanceRules;
use App\Livewire\Dashboard\EventParticipantScopes;
use App\Livewire\Dashboard\EventProposalCreate;
use App\Livewire\Dashboard\EventProposalWorkflow;
use App\Livewire\Dashboard\EventScannerAccess;
use App\Livewire\Dashboard\EventSentimentAction;
use App\Livewire\Dashboard\EventsWorkspace;
use App\Livewire\Dashboard\NotificationBell;
use App\Livewire\Dashboard\NotificationCenter;
use App\Livewire\Dashboard\NotificationSidebarBadge;
use App\Livewire\Dashboard\PendingQrLinks;
use App\Models\AdminAssignment;
use App\Models\AttendanceRecord;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\PendingStudentLink;
use App\Models\Program;
use App\Models\ProposalWorkflowTask;
use App\Models\RawScan;
use App\Models\ScannerSession;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventWorkflowNotification;
use App\Services\AdminAssignmentService;
use App\Services\Contracts\AiServiceInterface;
use App\Services\GeminiService;
use App\Services\ParticipationScopeService;
use App\Services\ProposalWorkflowTaskService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use App\Support\EvaluationQuestions;
use App\Support\EvaluationWindow;
use App\Support\EventEvaluationForm;
use App\Support\EventExportData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
            'manage-organizations', 'approve-proposals', 'approve-security-clearance', 'view-all-analytics',
            'force-validate-any', 'manage-global-settings', 'create-proposals',
            'assign-scanners-own', 'set-attendance-policy', 'set-late-threshold', 'view-own-analytics',
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
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'set-attendance-policy', 'set-late-threshold', 'view-own-analytics', 'force-validate-own']);
        Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'])
            ->syncPermissions(['register-profile', 'view-own-history', 'submit-evaluations']);
        Role::firstOrCreate(['name' => 'USG Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'assign-scanners-own', 'set-attendance-policy', 'view-own-analytics']);
        Role::firstOrCreate(['name' => 'LSG Admin', 'guard_name' => 'web'])
            ->syncPermissions(['create-proposals', 'set-attendance-policy', 'view-college-analytics', 'view-constituent-data']);
        Role::firstOrCreate(['name' => 'Chief Security', 'guard_name' => 'web'])
            ->syncPermissions(['approve-security-clearance']);
    }

    private function createHierarchy(): array
    {
        $uni = University::create(['name' => 'USM', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $uni->id, 'name' => 'CEIT', 'code' => 'CEIT']);
        $department = Department::create(['college_id' => $college->id, 'name' => 'Department of Information Systems', 'code' => 'DIS']);
        $org = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS',
        ]);
        $org->programs()->attach($program->id);
        $schoolYear = SchoolYear::firstOrCreate(
            ['label' => '2026-2027'],
            [
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
                'status' => SchoolYear::STATUS_ACTIVE,
                'activated_at' => now(),
            ]
        );

        return compact('uni', 'college', 'department', 'org', 'program', 'schoolYear');
    }

    private function createStudent(Organization $org): User
    {
        $program = Program::where('code', 'BSIS')->first() ?? Program::create([
            'department_id' => $org->department_id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS',
        ]);

        $user = User::create([
            'name' => 'Test Student',
            'email' => 'student'.uniqid().'@usm.edu.ph',
            'password' => null,
            'organization_id' => $org->id,
            'college_id' => $org->college_id,
            'department_id' => $org->department_id,
            'program_id' => $program->id,
            'program_code' => $program->code,
            'year_level' => '3rd_year',
        ]);
        $user->assignRole('Student');

        return $user;
    }

    /** @param array<string, mixed> $hierarchy */
    private function createMembership(User $student, array $hierarchy, string $yearLevel): OrganizationMembership
    {
        return OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $hierarchy['org']->id,
            'college_id' => $hierarchy['college']->id,
            'department_id' => $hierarchy['department']->id,
            'program_id' => $hierarchy['program']->id,
            'year_level' => $yearLevel,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'academic_year' => $hierarchy['schoolYear']->label,
            'school_year_id' => $hierarchy['schoolYear']->id,
            'source' => 'test-fixture',
            'verified_at' => now(),
        ]);
    }

    private function approveAvailableWorkflowTasks(Event $event): Event
    {
        $workflowTasks = app(ProposalWorkflowTaskService::class);
        $run = $event->workflowRun()->firstOrFail();

        while ($task = ProposalWorkflowTask::query()
            ->with(['assignedUser', 'processStep', 'workflowRun.event'])
            ->where('workflow_run_id', $run->id)
            ->where('status', ProposalWorkflowTask::STATUS_AVAILABLE)
            ->orderBy('display_order')
            ->first()) {
            $workflowTasks->decide(
                $task,
                $task->assignedUser,
                ProposalWorkflowTask::DECISION_APPROVED,
                'Approved through the assigned Workflow Task.'
            );
        }

        return $event->fresh();
    }

    private function createAdmin(string $role, ?Organization $org = null): User
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@usm.edu.ph',
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
            isPrimaryAdmin: ! $existingPrimary
        );

        if ($assignmentOrganization && $role !== 'Super Admin (OSA)' && $assignmentOrganization->type !== 'hr_unit') {
            $this->ensureWorkflowApprovers($assignmentOrganization, $user);
        }

        return $user;
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
            ]
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
            'hardcopy_submitted' => in_array($ppaStatus, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW, EventParticipationScope::PPA_REVISION_REQUESTED, EventParticipationScope::PPA_APPROVED], true),
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'approved_at' => $ppaStatus === EventParticipationScope::PPA_APPROVED ? now() : null,
        ]);
    }

    private function notifyWorkflow(User $user, array $overrides = []): void
    {
        $user->notify(new EventWorkflowNotification(array_merge([
            'notification_key' => 'test_notification:'.uniqid(),
            'kind' => 'test_notice',
            'title' => 'Workflow notification',
            'body' => 'A workflow update is ready for review.',
            'action_label' => 'Open',
            'action_url' => route('dashboard.events'),
            'severity' => 'info',
            'needs_action' => false,
        ], $overrides)));
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
        $form = EventEvaluationForm::createForEvent(
            $event,
            null,
            EvaluationQuestions::defaultSchema(),
            'Evaluation - '.$event->title
        );

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
            'time_in' => Carbon::parse(today()->subDay()->toDateString().' 07:55:00'),
            'time_out' => Carbon::parse(today()->subDay()->toDateString().' 12:05:00'),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);

        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'evaluation_form_version_id' => $form->id,
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

    private function customEvaluationQuestions(): array
    {
        return [
            [
                'id' => 'activity_relevance',
                'label' => 'Activity relevance',
                'description' => 'Was the activity relevant to the stated audience?',
            ],
            [
                'id' => 'facilitator_quality',
                'label' => 'Facilitator quality',
                'description' => 'Did the facilitator support the session effectively?',
            ],
        ];
    }

    private function confirmVenueForEvent(Event $event, string $name = 'USM Auditorium'): Venue
    {
        $venue = Venue::create([
            'name' => $name,
            'campus_location' => 'Main Campus',
            'managing_office' => 'Campus Facilities Office',
            'is_active' => true,
        ]);
        $proposalPath = $event->proposal_document_path ?: 'proposal-documents/'.uniqid().'.pdf';
        $evidencePath = 'venue-confirmations/'.uniqid().'.pdf';
        Storage::put($proposalPath, 'proposal');
        Storage::put($evidencePath, 'confirmation');
        $event->update([
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
            'venue_confirmation_office' => $venue->managing_office,
            'venue_confirmation_date' => today(),
            'venue_confirmation_document_path' => $evidencePath,
            'venue_confirmation_document_original_name' => 'confirmation.pdf',
            'proposal_document_path' => $proposalPath,
            'proposal_document_original_name' => $event->proposal_document_original_name ?: 'proposal.pdf',
        ]);
        $event->canonicalPpaPacket()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'proposal_category' => $event->proposal_category ?: 'activity',
                'title' => $event->title,
                'proponent_name' => 'Test Proponent',
                'proponent_position' => 'Secretary',
                'proponent_organization' => $event->organization?->name ?: 'PSITS',
                'proponent_email' => 'test.proponent@usm.edu.ph',
                'proponent_contact_number' => '09170000000',
                'implementing_office' => $event->organization?->name ?: 'PSITS',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => '1500.00',
                'start_date' => $event->start_date,
                'end_date' => $event->end_date,
                'location_type' => $event->location_type,
                'venue_id' => $event->venue_id,
                'location_details' => $event->location_details,
                'venue_confirmation_office' => $event->venue_confirmation_office,
                'venue_confirmation_date' => $event->venue_confirmation_date,
                'venue_confirmation_reference_number' => $event->venue_confirmation_reference_number,
                'venue_confirmation_document_path' => $event->venue_confirmation_document_path,
                'venue_confirmation_document_original_name' => $event->venue_confirmation_document_original_name,
                'rationale' => 'A complete packet is required before submission.',
                'general_objective' => 'Submit a complete generated packet.',
                'specific_objectives' => ['Prepare packet details', 'Submit the proposal'],
                'methodology' => 'Coordinate, document, and submit the proposal packet.',
                'major_activities' => ['Planning workshop', 'Operations review'],
                'program_activities' => [[
                    'date' => $event->start_date?->toDateString() ?? today()->toDateString(),
                    'time' => '08:00 - 12:00',
                    'activity' => $event->title,
                    'person_in_charge' => 'Test Proponent',
                ]],
                'expected_output' => 'A submitted complete packet.',
                'beneficiaries' => 'Target participants',
                'budget_breakdown' => [['item' => 'Overall total', 'amount' => '1500.00']],
            ]
        );

        return $venue;
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

    public function test_notification_bell_renders_only_on_dashboard_overview_header(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $this->notifyWorkflow($admin, [
            'title' => 'Dashboard Header Notice',
            'body' => 'A participant workflow needs review from the overview.',
            'severity' => 'warning',
            'needs_action' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('notification-bell--dashboard', false)
            ->assertSee('notification-bell-badge', false)
            ->assertSee('Open notification menu')
            ->assertSee('sidebar-count-unread', false)
            ->assertSee('1 unread notifications')
            ->assertSee('Dashboard Header Notice')
            ->assertSee('Needs action');

        $this->actingAs($admin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertDontSee('notification-bell--dashboard', false)
            ->assertDontSee('Open notification menu')
            ->assertDontSee('Dashboard Header Notice');
    }

    public function test_livewire_notification_bell_marks_items_read_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $this->notifyWorkflow($admin, [
            'title' => 'Async Notification',
            'body' => 'This notification should be marked read in-place.',
            'needs_action' => true,
        ]);

        $notification = $admin->fresh()->notifications()->first();
        $this->assertNull($notification->read_at);

        Livewire::actingAs($admin)
            ->test(NotificationBell::class, ['variant' => 'dashboard'])
            ->assertSee('Async Notification')
            ->call('markRead', $notification->id)
            ->assertDispatched('notifications-updated');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_livewire_notification_bell_marks_all_read_and_refreshes_sidebar_badge(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $this->notifyWorkflow($admin, ['title' => 'First unread notice']);
        $this->notifyWorkflow($admin, ['title' => 'Second unread notice']);

        Livewire::actingAs($admin)
            ->test(NotificationBell::class, ['variant' => 'dashboard'])
            ->assertSee('First unread notice')
            ->call('markAllRead')
            ->assertDispatched('notifications-updated');

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());

        Livewire::actingAs($admin)
            ->test(NotificationSidebarBadge::class, ['type' => 'unread'])
            ->assertDontSee('sidebar-count-unread');
    }

    public function test_livewire_notification_center_marks_items_read_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $this->notifyWorkflow($admin, [
            'title' => 'Center Async Notification',
            'body' => 'This notification should be handled from the notification center.',
            'needs_action' => true,
        ]);

        $notification = $admin->fresh()->notifications()->first();
        $this->assertNull($notification->read_at);

        Livewire::actingAs($admin)
            ->test(NotificationCenter::class)
            ->assertSee('Center Async Notification')
            ->call('markRead', $notification->id)
            ->assertDispatched('notifications-updated');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_livewire_notification_center_marks_all_read_and_uses_livewire_pagination(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        foreach (range(1, 22) as $index) {
            $this->notifyWorkflow($admin, [
                'notification_key' => 'notification_center_page:'.$index,
                'title' => 'Paged Notification '.$index,
                'body' => 'Notification center pagination coverage.',
            ]);
        }

        Livewire::actingAs($admin)
            ->test(NotificationCenter::class)
            ->assertSee('Paged Notification 1')
            ->assertDontSee('Paged Notification 21')
            ->assertSee('wire:click="gotoPage', false)
            ->call('gotoPage', 2, 'page')
            ->assertSee('Paged Notification 21')
            ->assertSee('Paged Notification 22')
            ->call('markAllRead')
            ->assertDispatched('notifications-updated');

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
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
                'department_id' => $h['department']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => '2023-00100',
                'qr_code_value' => 'QR-UNIQUE-001',
            ])
            ->assertRedirect(route('portal.profile'))
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertEquals($h['college']->id, $student->college_id);
        $this->assertEquals($h['department']->id, $student->department_id);
        $this->assertEquals($h['program']->id, $student->program_id);
        $this->assertEquals('BSIS', $student->program_code);
        $this->assertEquals('3rd_year', $student->year_level);
        $this->assertEquals('2023-00100', $student->student_id_number);
        $this->assertEquals('QR-UNIQUE-001', $student->qr_code_value);

        $membership = OrganizationMembership::query()
            ->where('user_id', $student->id)
            ->where('organization_id', $h['org']->id)
            ->where('membership_type', 'primary_academic')
            ->where('source', 'profile')
            ->where('school_year_id', $h['schoolYear']->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertModelExists($membership);
        $this->assertSame($h['college']->id, $membership->college_id);
        $this->assertSame($h['department']->id, $membership->department_id);
        $this->assertSame($h['program']->id, $membership->program_id);
        $this->assertSame('3rd_year', $membership->year_level);
        $this->assertSame(OrganizationMembership::STATUS_ACTIVE, $membership->status);
    }

    public function test_profile_registration_rejects_invalid_academic_path_combinations(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        $otherCollege = College::create([
            'university_id' => $h['uni']->id,
            'name' => 'College of Arts and Sciences',
            'code' => 'CAS',
        ]);
        $otherDepartment = Department::create([
            'college_id' => $otherCollege->id,
            'name' => 'Department of Biology',
            'code' => 'BIO',
        ]);
        $sameCollegeOtherDepartment = Department::create([
            'college_id' => $h['college']->id,
            'name' => 'Department of Engineering',
            'code' => 'DOE',
        ]);
        $otherProgram = Program::create([
            'department_id' => $sameCollegeOtherDepartment->id,
            'name' => 'BS Agricultural Engineering',
            'code' => 'BSAE',
        ]);
        $uncoveredOrg = Organization::create([
            'college_id' => $h['college']->id,
            'department_id' => $h['department']->id,
            'name' => 'Unlinked Academic Society',
            'acronym' => 'UAS',
            'type' => 'society',
        ]);
        $departmentlessOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Departmentless Society',
            'acronym' => 'DLS',
            'type' => 'society',
        ]);
        $departmentlessOrg->programs()->attach($h['program']->id);
        $inactiveOrg = Organization::create([
            'college_id' => $h['college']->id,
            'department_id' => $h['department']->id,
            'name' => 'Inactive Academic Society',
            'acronym' => 'IAS',
            'type' => 'society',
            'status' => 'inactive',
        ]);
        $inactiveOrg->programs()->attach($h['program']->id);

        $payload = [
            'college_id' => $h['college']->id,
            'department_id' => $h['department']->id,
            'program_id' => $h['program']->id,
            'organization_id' => $h['org']->id,
            'year_level' => '3rd_year',
            'student_id_number' => '2023-00150',
            'qr_code_value' => 'QR-UNIQUE-PATH',
        ];

        $this->actingAs($student)
            ->post(route('portal.profile.update'), array_merge($payload, [
                'department_id' => $otherDepartment->id,
            ]))
            ->assertSessionHasErrors('department_id');

        $this->actingAs($student)
            ->post(route('portal.profile.update'), array_merge($payload, [
                'program_id' => $otherProgram->id,
            ]))
            ->assertSessionHasErrors('program_id');

        $this->actingAs($student)
            ->post(route('portal.profile.update'), array_merge($payload, [
                'organization_id' => $uncoveredOrg->id,
            ]))
            ->assertSessionHasErrors('organization_id');

        $this->actingAs($student)
            ->post(route('portal.profile.update'), array_merge($payload, [
                'organization_id' => $departmentlessOrg->id,
            ]))
            ->assertSessionHasErrors('organization_id');

        $this->actingAs($student)
            ->post(route('portal.profile.update'), array_merge($payload, [
                'organization_id' => $inactiveOrg->id,
            ]))
            ->assertSessionHasErrors('organization_id');
    }

    public function test_profile_registration_reties_legacy_primary_membership_to_active_school_year(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $legacyMembership = OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $h['org']->id,
            'college_id' => $h['college']->id,
            'department_id' => $h['department']->id,
            'program_id' => $h['program']->id,
            'year_level' => '2nd_year',
            'membership_type' => 'primary_academic',
            'source' => 'profile',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'verified_at' => now()->subDay(),
        ]);

        $this->actingAs($student)
            ->post(route('portal.profile.update'), [
                'college_id' => $h['college']->id,
                'department_id' => $h['department']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => '2023-00152',
                'qr_code_value' => 'QR-UNIQUE-LEGACY-SY',
            ])
            ->assertRedirect(route('portal.profile'));

        $this->assertSame('inactive', $legacyMembership->fresh()->status);

        $activeMembership = OrganizationMembership::query()
            ->where('user_id', $student->id)
            ->where('organization_id', $h['org']->id)
            ->where('school_year_id', $h['schoolYear']->id)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($activeMembership);
        $this->assertModelExists($activeMembership);
    }

    public function test_profile_registration_requires_active_school_year_for_membership(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        SchoolYear::query()->update(['status' => SchoolYear::STATUS_DRAFT]);

        $this->actingAs($student)
            ->post(route('portal.profile.update'), [
                'college_id' => $h['college']->id,
                'department_id' => $h['department']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => '2023-00151',
                'qr_code_value' => 'QR-UNIQUE-NO-SY',
            ])
            ->assertSessionHasErrors('school_year');

        $this->assertFalse(
            OrganizationMembership::query()
                ->where('user_id', $student->id)
                ->where('school_year_id', $h['schoolYear']->id)
                ->exists()
        );
    }

    public function test_profile_registration_requires_valid_year_level(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $payload = [
            'college_id' => $h['college']->id,
            'department_id' => $h['department']->id,
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
                'department_id' => $h['department']->id,
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
                'department_id' => $h['department']->id,
                'program_id' => $h['program']->id,
                'organization_id' => $h['org']->id,
                'year_level' => '3rd_year',
                'student_id_number' => 'ID-TAKEN',
                'qr_code_value' => 'QR-UNIQUE-002',
            ])
            ->assertSessionHasErrors('student_id_number');
    }

    // ─── Evaluation Tests ──────────────────────────────────

    public function test_student_evaluations_index_renders_empty_pending_submitted_and_closed_states(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->get(route('portal.evaluations'))
            ->assertOk()
            ->assertSee('My Evaluations')
            ->assertSee('No evaluations to complete')
            ->assertSee('No submitted evaluations yet');

        $pendingEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Pending Evaluation Center Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventEvaluationForm::createForEvent($pendingEvent, null, $this->customEvaluationQuestions(), 'Evaluation - Pending Center Event');
        $pendingDay = EventDay::create([
            'event_id' => $pendingEvent->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        AttendanceRecord::create([
            'event_id' => $pendingEvent->id,
            'event_day_id' => $pendingDay->id,
            'student_id' => $student->id,
            'time_in' => now(),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => false,
        ]);

        $submittedEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Submitted Evaluation Center Event',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);
        $submittedForm = EventEvaluationForm::createForEvent($submittedEvent, null, $this->customEvaluationQuestions(), 'Evaluation - Submitted Center Event');
        $submittedDay = EventDay::create([
            'event_id' => $submittedEvent->id,
            'day_number' => 1,
            'date' => today()->subDay(),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        AttendanceRecord::create([
            'event_id' => $submittedEvent->id,
            'event_day_id' => $submittedDay->id,
            'student_id' => $student->id,
            'time_in' => now()->subDay(),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);
        Evaluation::create([
            'event_id' => $submittedEvent->id,
            'student_id' => $student->id,
            'evaluation_form_version_id' => $submittedForm->id,
            'section_scores' => [
                'activity_relevance' => 5,
                'facilitator_quality' => 4,
            ],
            'open_comment' => 'Useful event.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now()->subHours(2),
        ]);

        $closedEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Closed Evaluation Center Event',
            'status' => 'completed',
            'start_date' => today()->subDays(5),
            'end_date' => today()->subDays(5),
        ]);
        EventEvaluationForm::createForEvent($closedEvent, null, $this->customEvaluationQuestions(), 'Evaluation - Closed Center Event');
        $closedDay = EventDay::create([
            'event_id' => $closedEvent->id,
            'day_number' => 1,
            'date' => today()->subDays(5),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);
        AttendanceRecord::create([
            'event_id' => $closedEvent->id,
            'event_day_id' => $closedDay->id,
            'student_id' => $student->id,
            'time_in' => now()->subDays(5),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => false,
        ]);

        $this->actingAs($student)
            ->get(route('portal.evaluations'))
            ->assertOk()
            ->assertSee('Pending Evaluation Center Event')
            ->assertSee('Evaluate now')
            ->assertSee('Submitted Evaluation Center Event')
            ->assertSee('Validated')
            ->assertSee('Closed Evaluation Center Event')
            ->assertSee('Closed Evaluation Windows');
    }

    public function test_route_errors_render_friendly_pages_for_wrong_web_requests(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $this->actingAs($student)
            ->get('/logout')
            ->assertStatus(405)
            ->assertSee('This action cannot be opened directly')
            ->assertSee('GET /logout')
            ->assertSee('Return to Portal');

        $this->actingAs($student)
            ->get('/portal/not-a-real-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Return to Portal');

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertForbidden()
            ->assertSee('Access restricted')
            ->assertSee('Return to Portal');
    }

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
        $form = EventEvaluationForm::createForEvent($event, null, $this->customEvaluationQuestions(), 'Evaluation - Test Event');

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

        $windowCloseAt = EvaluationWindow::closesAt($event);

        $this->actingAs($student)
            ->get(route('portal.evaluation', $event))
            ->assertOk()
            ->assertSee('Student evaluation')
            ->assertSee('Activity relevance')
            ->assertSee('Facilitator quality')
            ->assertSee('Evaluation window')
            ->assertSee('Time remaining')
            ->assertSee('Closes')
            ->assertSee($windowCloseAt->format('M d, Y g:i A'))
            ->assertSee('Pending evaluation');

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'activity_relevance' => 4,
                    'facilitator_quality' => 5,
                ],
                'open_comment' => 'Great event!',
            ])
            ->assertRedirect(route('portal'))
            ->assertSessionHas('success');

        // Verify evaluation created
        $this->assertDatabaseHas('evaluations', [
            'event_id' => $event->id,
            'student_id' => $student->id,
            'evaluation_form_version_id' => $form->id,
        ]);

        // Verify evaluation gate toggled validity
        $this->assertDatabaseHas('attendance_records', [
            'event_id' => $event->id,
            'student_id' => $student->id,
            'valid' => true,
        ]);

        $this->actingAs($student)
            ->get(route('portal.evaluation', $event))
            ->assertOk()
            ->assertSee('Evaluation Submitted')
            ->assertSee('Attendance validated')
            ->assertSee('Submitted')
            ->assertSee($windowCloseAt->format('M d, Y g:i A'));

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'activity_relevance' => 4,
                    'facilitator_quality' => 5,
                ],
            ])
            ->assertSessionHas('error', 'You have already submitted an evaluation for this event.');
    }

    public function test_evaluation_submission_validates_event_specific_questions(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Custom Evaluation Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventEvaluationForm::createForEvent($event, null, $this->customEvaluationQuestions(), 'Evaluation - Custom Evaluation Event');

        $eventDay = EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

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
                    'activity_relevance' => 4,
                ],
            ])
            ->assertSessionHasErrors('section_scores.facilitator_quality');

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'activity_relevance' => 6,
                    'facilitator_quality' => 5,
                ],
            ])
            ->assertSessionHasErrors('section_scores.activity_relevance');

        $this->actingAs($student)
            ->post(route('portal.evaluation.submit'), [
                'event_id' => $event->id,
                'section_scores' => [
                    'activity_relevance' => 4,
                    'facilitator_quality' => 5,
                    'speaker_mastery' => 5,
                ],
            ])
            ->assertSessionHasErrors('section_scores.speaker_mastery');

        $this->assertDatabaseMissing('evaluations', [
            'event_id' => $event->id,
            'student_id' => $student->id,
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

        $windowCloseAt = EvaluationWindow::closesAt($event);

        $this->actingAs($student)
            ->get(route('portal.evaluation', $event))
            ->assertOk()
            ->assertSee('Window Closed')
            ->assertSee('Evaluation window')
            ->assertSee('No time remaining')
            ->assertSee('The deadline was')
            ->assertSee($windowCloseAt->format('M d, Y g:i A'));

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

        // Create event as Society Admin
        $event = Event::create([
            'organization_id' => $h['org']->id,
            'school_year_id' => $h['schoolYear']->id,
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
        EventEvaluationForm::createForEvent($event, $societyAdmin, $this->customEvaluationQuestions(), 'Evaluation - '.$event->title);
        $this->confirmVenueForEvent($event, 'Status Test Venue');

        // Submit
        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $event->id))
            ->assertSessionHas('success');
        $event->refresh();
        $this->assertEquals('submitted', $event->status);

        $approved = $this->approveAvailableWorkflowTasks($event);

        $this->assertEquals('approved', $approved->status);
        $this->assertDatabaseHas('approval_trails', [
            'proposal_id' => $event->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
        ]);
    }

    public function test_livewire_event_proposal_workflow_submits_draft_without_page_reload(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Submit Proposal',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/livewire-submit.docx',
            'proposal_document_original_name' => 'livewire-submit.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);
        EventEvaluationForm::createForEvent($event, $societyAdmin, $this->customEvaluationQuestions(), 'Evaluation - '.$event->title);
        $this->confirmVenueForEvent($event, 'Livewire Submit Venue');

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->assertSee('Submit')
            ->call('submitProposal')
            ->assertSet('notice', 'Proposal submitted for review.')
            ->assertSee('Proposal submitted for review.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
        ]);
    }

    public function test_livewire_event_proposal_workflow_keeps_legacy_hardcopy_projection_read_only(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Hardcopy Proposal',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/livewire-hardcopy.docx',
            'proposal_document_original_name' => 'livewire-hardcopy.docx',
            'hardcopy_submitted' => false,
            'head_organization_signed' => false,
            'adviser_signed' => false,
        ]);

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->call('markHardcopy')
            ->assertSet('errorNotice', 'Legacy hardcopy and signature fields are read-only workflow projections.');

        $this->assertFalse((bool) $event->fresh()->hardcopy_submitted);

        Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->assertSee('Hardcopy')
            ->assertSee('ppa-status-item--warn', false);
    }

    public function test_livewire_event_proposal_workflow_does_not_expose_global_osa_decisions(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire OSA Review Proposal',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/livewire-review.docx',
            'proposal_document_original_name' => 'livewire-review.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);
        EventEvaluationForm::createForEvent($event, $societyAdmin, $this->customEvaluationQuestions(), 'Evaluation - '.$event->title);
        $this->confirmVenueForEvent($event, 'Livewire Review Venue');

        Livewire::actingAs($osaAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->assertDontSeeHtml('wire:click="startReview"')
            ->assertDontSeeHtml('wire:click="approveProposal"')
            ->assertDontSeeHtml('wire:submit="requestRevision"')
            ->assertDontSeeHtml('wire:click="rejectProposal"');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'submitted',
        ]);
    }

    public function test_livewire_event_proposal_revision_allows_full_edit_and_resubmit(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'school_year_id' => $h['schoolYear']->id,
            'title' => 'Revision Candidate Proposal',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/original.docx',
            'proposal_document_original_name' => 'original.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
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
        EventEvaluationForm::createForEvent($event, $societyAdmin, $this->customEvaluationQuestions(), 'Evaluation - '.$event->title);
        $revisionVenue = $this->confirmVenueForEvent($event);

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->call('submitProposal')
            ->assertSet('errorNotice', null)
            ->assertSet('notice', 'Proposal submitted for review.');

        $revisionTask = ProposalWorkflowTask::query()
            ->where('workflow_run_id', $event->fresh()->workflowRun()->firstOrFail()->id)
            ->where('status', ProposalWorkflowTask::STATUS_AVAILABLE)
            ->orderBy('display_order')
            ->firstOrFail();
        app(ProposalWorkflowTaskService::class)->decide(
            $revisionTask,
            $revisionTask->assignedUser,
            ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'Update venue, budget, and evaluation questions.',
            ['type' => 'packet_section', 'key' => 'budget']
        );

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => Event::STATUS_REVISION_REQUESTED,
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
            'review_remarks' => 'Update venue, budget, and evaluation questions.',
        ]);
        $this->assertTrue(
            $societyAdmin->fresh()->notifications->contains(
                fn ($notification): bool => data_get($notification->data, 'kind') === 'workflow_task_revision_requested'
            )
        );

        $this->travel(1)->seconds();

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalCreate::class, ['eventId' => $event->id])
            ->set('title', 'Revised Candidate Proposal')
            ->set('proposalCategory', 'program')
            ->set('startDate', today()->addDays(8)->toDateString())
            ->set('endDate', today()->addDays(8)->toDateString())
            ->set('locationType', 'on-campus')
            ->set('venueId', $revisionVenue->id)
            ->set('venueConfirmationOffice', $revisionVenue->managing_office)
            ->set('venueConfirmationDate', today()->toDateString())
            ->set('implementingOffice', 'PSITS')
            ->set('targetParticipants', 'All society members')
            ->set('sourceOfFund', 'Organization fund')
            ->set('budgetCost', '1750')
            ->set('majorActivities', "Planning workshop\nOperations review")
            ->set('resolutionNumber', 'RES-2026-01')
            ->set('hardcopySubmitted', true)
            ->set('headOrganizationSigned', true)
            ->set('adviserSigned', true)
            ->set('eventDays', [[
                'date' => today()->addDays(8)->toDateString(),
                'start_time' => '09:00',
                'end_time' => '15:00',
            ]])
            ->set('evaluationQuestions', [[
                'id' => 'overall_revision',
                'label' => 'How effective was the revised activity?',
                'description' => 'Rate the overall activity.',
            ]])
            ->set('proposalDocument', UploadedFile::fake()->create('revised-proposal.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->call('createProposal')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard.events', ['status' => Event::STATUS_REVISION_REQUESTED]));

        $event->refresh();
        $this->assertSame(Event::STATUS_REVISION_REQUESTED, $event->status);
        $this->assertSame('Revised Candidate Proposal', $event->title);
        $this->assertSame('revised-proposal.docx', $event->proposal_document_original_name);
        $this->assertSame($revisionVenue->displayName(), $event->location_details);
        $this->assertSame('1750', (string) ($event->budget_allocations['budget_cost'] ?? ''));
        $this->assertDatabaseHas('event_days', [
            'event_id' => $event->id,
            'day_number' => 1,
            'start_time' => '09:00',
            'end_time' => '15:00',
        ]);

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->call('submitProposal')
            ->assertSet('errorNotice', null)
            ->assertSet('notice', 'Proposal submitted for review.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => Event::STATUS_SUBMITTED,
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'review_remarks' => null,
        ]);
    }

    public function test_livewire_event_proposal_workflow_keeps_global_approval_unavailable(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Missing Form Proposal',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/livewire-missing-form.docx',
            'proposal_document_original_name' => 'livewire-missing-form.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
        ]);

        Livewire::actingAs($osaAdmin)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->assertDontSeeHtml('wire:click="approveProposal"');

        $this->assertSame('submitted', $event->fresh()->status);
    }

    public function test_osa_cannot_use_retired_event_approval_route(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Missing Evaluation Form Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
            'proposal_document_path' => 'proposal-documents/missing-evaluation.docx',
            'proposal_document_original_name' => 'missing-evaluation.docx',
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
        ]);

        $this->actingAs($osaAdmin)
            ->post("/dashboard/events/{$event->id}/approve")
            ->assertNotFound();

        $this->assertSame('submitted', $event->fresh()->status);
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

        foreach ([Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW, Event::STATUS_REVISION_REQUESTED, Event::STATUS_APPROVED, Event::STATUS_REJECTED, Event::STATUS_COMPLETED] as $status) {
            Event::create([
                'organization_id' => $h['org']->id,
                'title' => 'Visible '.ucwords(str_replace('_', ' ', $status)),
                'status' => $status,
                'start_date' => today()->addDays(7),
                'end_date' => today()->addDays(7),
            ]);
        }

        $response = $this->actingAs($osaAdmin)
            ->get(route('dashboard.events'))
            ->assertOk();

        foreach (['Submitted', 'Under Review', 'Approved', 'Rejected', 'Completed'] as $label) {
            $response->assertSee('Visible '.$label);
        }
    }

    public function test_events_filters_search_and_pagination_use_livewire_workspace(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        foreach (range(1, 16) as $index) {
            Event::create([
                'organization_id' => $h['org']->id,
                'title' => 'Navigable Event '.$index,
                'status' => $index === 1 ? 'submitted' : 'draft',
                'start_date' => today()->addDays($index),
                'end_date' => today()->addDays($index),
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $content = $this->actingAs($societyAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('dashboard.events-workspace', $content);
        $this->assertStringContainsString('events-nav-row', $content);
        $this->assertStringContainsString('events-status-row', $content);
        $this->assertStringContainsString('events-search-inline', $content);
        $this->assertStringContainsString('wire:click.prevent="setWorkspace', $content);
        $this->assertStringContainsString('wire:submit.prevent="applyFilters"', $content);
        $this->assertStringContainsString('eventRowVisible', $content);
        $this->assertStringContainsString('x-model.debounce.150ms="eventSearch"', $content);
        $this->assertStringContainsString('data-event-search=', $content);
        $this->assertStringContainsString('wire:model.live.debounce.650ms="search"', $content);
        $this->assertStringNotContainsString('wire:input.debounce.350ms="searchTyped', $content);
        $this->assertStringContainsString('name="workspace"', $content);
        $this->assertStringContainsString('name="status"', $content);
        $this->assertStringContainsString('name="q"', $content);
        $this->assertStringNotContainsString('wire:model.live.debounce.400ms="search"', $content);
        $this->assertStringNotContainsString('selectSearchSuggestion', $content);
        $this->assertStringNotContainsString('events-search-results', $content);
        $this->assertStringNotContainsString('name="per_page"', $content);
        $this->assertStringNotContainsString('events-workspace-per-page', $content);
        $this->assertStringNotContainsString('tailwind-navigate', $content);
        $this->assertStringContainsString('initEventsDashboard', $content);
        $this->assertStringContainsString('livewire:navigated', $content);

        Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->set('search', 'navigable event 1')
            ->assertSee('events-search-summary', false)
            ->assertSee('Navigable Event 1')
            ->assertDontSee('Navigable Event 2')
            ->set('search', '')
            ->call('setStatus', 'submitted')
            ->assertSee('Navigable Event 1')
            ->assertDontSee('Navigable Event 2')
            ->call('setStatus', null)
            ->set('perPage', 10)
            ->assertSee('Navigable Event 1')
            ->assertDontSee('Navigable Event 16')
            ->call('nextPage')
            ->assertSee('Navigable Event 16');
    }

    public function test_events_workspace_search_is_case_insensitive(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Mixed Case Search Event',
            'proposal_category' => 'Seminar',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);

        $workspace = Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->set('search', 'mixed case')
            ->assertSee('Mixed Case Search Event');

        $workspace
            ->set('search', 'seminar')
            ->assertSee('Mixed Case Search Event')
            ->set('search', 'psits')
            ->assertSee('Mixed Case Search Event');
    }

    public function test_events_workspace_mounts_only_the_selected_action_component(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Lazy Workflow Event',
            'proposal_category' => 'activity',
            'status' => 'draft',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);

        Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->assertSee('Lazy Workflow Event')
            ->assertDontSee('Proposal Workflow')
            ->assertDontSee('dashboard.event-proposal-workflow', false)
            ->assertDontSee('dashboard.event-proposal-packet', false)
            ->assertDontSee('dashboard.event-proposal-status', false)
            ->call('openModal', 'workflow', $event->id)
            ->assertSet('activeModal', 'workflow')
            ->assertSet('activeEventId', $event->id)
            ->assertSee('Proposal Workflow')
            ->assertSee('dashboard.event-proposal-workflow', false)
            ->assertSee('Submit')
            ->call('closeModal')
            ->assertSet('activeModal', null)
            ->assertSet('activeEventId', null)
            ->assertDontSee('Proposal Workflow');
    }

    public function test_events_workspace_refreshes_static_rows_after_workflow_and_scope_updates(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Refreshable Workspace Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
        ]);

        $workspace = Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class, ['workspace' => 'participating'])
            ->assertSee('badge-submitted', false);

        $event->update(['status' => 'under_review']);
        $workspace
            ->dispatch('event-proposal-workflow-updated')
            ->assertSee('Under Review');

        $scope->update(['ppa_status' => EventParticipationScope::PPA_APPROVED]);
        $workspace
            ->dispatch('participant-scopes-updated')
            ->assertSee('badge-approved', false);
    }

    public function test_events_workspace_rejects_modal_selection_outside_the_current_scope(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $otherOrganization = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Outside Society',
            'acronym' => 'OUTSIDE',
            'type' => 'society',
        ]);
        $event = Event::create([
            'organization_id' => $otherOrganization->id,
            'title' => 'Outside Workspace Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);

        Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->call('openModal', 'workflow', $event->id)
            ->assertNotFound();
    }

    public function test_osa_does_not_see_participant_invitation_acknowledgement_actions(): void
    {
        $h = $this->createHierarchy();
        $osaAdmin = $this->createAdmin('Super Admin (OSA)');
        $partnerOrganization = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Acknowledgement Partner',
            'acronym' => 'ACK-PARTNER',
            'type' => 'society',
        ]);
        $partnerOrganization->programs()->attach($h['program']->id);
        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'OSA Participant Invite Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);
        $this->createParticipantScope($event, $partnerOrganization);

        $workspace = Livewire::actingAs($osaAdmin)
            ->test(EventsWorkspace::class, ['workspace' => 'participating'])
            ->assertSee('OSA Participant Invite Event')
            ->assertDontSee('acknowledgeParticipantInvitation', false);

        $workspace
            ->call('openModal', 'workflow', $event->id)
            ->assertSee('Proposal Workflow')
            ->assertDontSee('acknowledgeParticipantInvitation', false);
    }

    public function test_events_workspace_refreshes_stale_lifecycle_on_page_open(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        Carbon::setTestNow(Carbon::parse('2026-07-22 15:00:00', 'Asia/Manila'));

        try {
            $event = Event::create([
                'organization_id' => $h['org']->id,
                'title' => 'Stale Lifecycle Event',
                'proposal_category' => 'activity',
                'status' => 'approved',
                'lifecycle_phase' => 'evaluation_open',
                'evaluation_open' => true,
                'start_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
                'end_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            ]);

            EventDay::create([
                'event_id' => $event->id,
                'day_number' => 1,
                'date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
                'start_time' => '20:30:00',
                'end_time' => '23:00:00',
            ]);

            $this->actingAs($societyAdmin)
                ->get(route('dashboard.events'))
                ->assertOk()
                ->assertSee('Stale Lifecycle Event')
                ->assertSee('Completed')
                ->assertSee('Phase: Completed');

            $event->refresh();

            $this->assertSame('completed', $event->status);
            $this->assertSame('completed', $event->lifecycle_phase);
            $this->assertFalse($event->evaluation_open);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_events_workspace_counts_pending_sentiment_comments_without_render_error(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Pending Sentiment Count Event',
            'proposal_category' => 'activity',
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
            'section_scores' => ['activity_relevance' => 5],
            'open_comment' => 'Needs sentiment processing.',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        Evaluation::create([
            'event_id' => $event->id,
            'student_id' => $student->id,
            'section_scores' => ['activity_relevance' => 4],
            'open_comment' => '   ',
            'sentiment' => 'unprocessed',
            'submitted_at' => now(),
        ]);

        $this->assertSame(1, Evaluation::query()->pendingSentimentComments()->count());

        Livewire::actingAs($societyAdmin)
            ->test(EventsWorkspace::class)
            ->assertSee('Pending Sentiment Count Event')
            ->assertSee('AI');
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
        $this->confirmVenueForEvent($draft, 'Society Draft Venue');

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Society Private Draft')
            ->assertSee('New Event')
            ->assertSee('wire:submit.prevent="createProposal"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="proposal_document"', false)
            ->assertSee('name="event_days[0][date]"', false)
            ->assertSee('name="same_time_for_all_days"', false)
            ->assertSee('name="evaluation_questions[0][label]"', false)
            ->assertSee('Schedule and attendance')
            ->assertSee('Daily attendance schedule')
            ->assertSee('Same time for all days')
            ->assertSee('Customize questions')
            ->assertSee('Save Draft Proposal')
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
        EventEvaluationForm::createForEvent($event, null, $this->customEvaluationQuestions(), 'Evaluation - Metadata Review Event');

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
            ->assertSee('data-detail-evaluation-questions', false)
            ->assertSee('Activity relevance')
            ->assertSee('Facilitator quality')
            ->assertSee('Start time')
            ->assertSee('End time')
            ->assertSee('D1:')
            ->assertSee('8:00 AM - 12:00 PM');
    }

    public function test_event_creation_allows_incomplete_draft_and_tracks_uploaded_packet(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $basePayload = [
            'title' => 'Documented PPA Event',
            'proposal_category' => 'activity',
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'start_date' => today()->addDays(10)->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'location_type' => 'on-campus',
            'location_details' => 'USM Gymnasium',
            'implementing_office' => 'PSITS',
            'target_participants' => 'All BSIS students',
            'source_of_fund' => 'Organization fund',
            'budget_cost' => 2500,
            'resolution_number' => 'RES-2026-001',
            'proponent_name' => 'Fallback Secretary',
            'proponent_position' => 'Secretary',
            'proponent_organization' => 'PSITS',
            'proponent_email' => 'fallback.secretary@usm.edu.ph',
            'proponent_contact_number' => '09170000000',
            'rationale' => 'A fallback request should still create the canonical packet.',
            'general_objective' => 'Keep proposal and permit data captured once.',
            'specific_objectives' => "Capture packet fields\nSynchronize compatibility fields",
            'methodology' => 'Normal multipart form submission.',
            'major_activities' => "Preparation\nSubmission",
            'expected_output' => 'One structured canonical packet.',
            'hardcopy_submitted' => '1',
            'head_organization_signed' => '1',
            'adviser_signed' => '1',
            'event_days' => [[
                'date' => today()->addDays(10)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => $this->customEvaluationQuestions(),
        ];

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), $basePayload)
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('success');

        $incompleteDraft = Event::where('title', 'Documented PPA Event')->firstOrFail();
        $this->assertNull($incompleteDraft->proposal_document_path);
        $incompleteDraft->delete();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), array_merge($basePayload, [
                'proposal_document' => UploadedFile::fake()->create('official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'evaluation_questions' => [],
            ]))
            ->assertSessionHasErrors('evaluation_questions');

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), array_merge($basePayload, [
                'proposal_document' => UploadedFile::fake()->create('official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ]))
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('success');

        $event = Event::with('canonicalPpaPacket')->where('title', 'Documented PPA Event')->first();
        $this->assertNotNull($event);
        $this->assertSame('activity', $event->proposal_category);
        $this->assertFalse($event->hardcopy_submitted);
        $this->assertFalse($event->head_organization_signed);
        $this->assertFalse($event->adviser_signed);
        $this->assertSame('official-ppa.docx', $event->proposal_document_original_name);
        $this->assertNotNull($event->canonicalPpaPacket);
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event->canonicalPpaPacket->activity_role);
        $this->assertSame($event->title, $event->canonicalPpaPacket->title);
        $this->assertSame($event->location_details, $event->canonicalPpaPacket->location_details);
        $this->assertSame($event->budget_allocations['source_of_fund'], $event->canonicalPpaPacket->source_of_fund);
        $this->assertSame(['Capture packet fields', 'Synchronize compatibility fields'], $event->canonicalPpaPacket->specific_objectives);
        $this->assertNotNull($event->evaluation_form_version_id);
        $this->assertDatabaseHas('evaluation_form_versions', [
            'event_id' => $event->id,
            'title' => 'Evaluation - Documented PPA Event',
        ]);
        Storage::disk('local')->assertExists($event->proposal_document_path);
    }

    public function test_event_creation_fallback_preserves_typed_modal_values(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $this->actingAs($societyAdmin)
            ->from(route('dashboard.events'))
            ->post(route('dashboard.events.create'), [
                'title' => 'Fallback Preserved Event',
                'proposal_category' => 'activity',
                'start_date' => '2026-07-17',
                'end_date' => '2026-07-18',
                'location_type' => 'off-campus',
                'location_details' => 'Kabacan Training Center',
                'implementing_office' => 'PSITS',
                'collaborating_office' => 'Guidance Office',
                'target_participants' => 'All BSIS students',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 2500,
                'resolution_number' => 'RES-2026-010',
                'hardcopy_submitted' => '1',
                'head_organization_signed' => '1',
                'adviser_signed' => '1',
                'venue_confirmation_document' => UploadedFile::fake()->create('invalid-confirmation.exe', 128, 'application/octet-stream'),
                'same_time_for_all_days' => '1',
                'event_days' => [
                    [
                        'date' => '2026-07-17',
                        'start_time' => '09:00',
                        'end_time' => '13:00',
                    ],
                    [
                        'date' => '2026-07-18',
                        'start_time' => '09:00',
                        'end_time' => '13:00',
                    ],
                ],
                'evaluation_questions' => $this->customEvaluationQuestions(),
            ])
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHasErrors('venue_confirmation_document');

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('value="Fallback Preserved Event"', false)
            ->assertSee('value="2026-07-17"', false)
            ->assertSee('value="2026-07-18"', false)
            ->assertSee('value="09:00"', false)
            ->assertSee('value="13:00"', false)
            ->assertSee('Kabacan Training Center')
            ->assertSee('Guidance Office')
            ->assertSee('RES-2026-010')
            ->assertSee('Activity relevance')
            ->assertSee('Facilitator quality');
    }

    public function test_event_creation_rejects_event_day_outside_event_date_range(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), [
                'title' => 'Mismatched Date Event',
                'proposal_category' => 'activity',
                'start_date' => '2026-07-17',
                'end_date' => '2026-07-17',
                'location_type' => 'on-campus',
                'location_details' => 'USM Gymnasium',
                'implementing_office' => 'PSITS',
                'target_participants' => 'All BSIS students',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 2500,
                'resolution_number' => 'RES-2026-002',
                'hardcopy_submitted' => '1',
                'head_organization_signed' => '1',
                'adviser_signed' => '1',
                'proposal_document' => UploadedFile::fake()->create('official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'event_days' => [[
                    'date' => '2026-01-17',
                    'start_time' => '20:30',
                    'end_time' => '23:00',
                ]],
                'evaluation_questions' => $this->customEvaluationQuestions(),
            ])
            ->assertSessionHasErrors('event_days.0.date');

        $this->assertDatabaseMissing('events', [
            'title' => 'Mismatched Date Event',
        ]);
    }

    public function test_event_creation_uses_friendly_event_day_time_errors(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), [
                'title' => 'Invalid Day Time Event',
                'proposal_category' => 'activity',
                'start_date' => '2026-07-17',
                'end_date' => '2026-07-17',
                'location_type' => 'on-campus',
                'location_details' => 'USM Gymnasium',
                'implementing_office' => 'PSITS',
                'target_participants' => 'All BSIS students',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 2500,
                'resolution_number' => 'RES-2026-003',
                'hardcopy_submitted' => '1',
                'head_organization_signed' => '1',
                'adviser_signed' => '1',
                'proposal_document' => UploadedFile::fake()->create('official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                'event_days' => [[
                    'date' => '2026-07-17',
                    'start_time' => '20:30',
                    'end_time' => '08:30',
                ]],
                'evaluation_questions' => $this->customEvaluationQuestions(),
            ])
            ->assertSessionHasErrors([
                'event_days.0.end_time' => 'Day 1 end time must be later than the start time.',
            ]);

        $this->assertDatabaseMissing('events', [
            'title' => 'Invalid Day Time Event',
        ]);
    }

    public function test_livewire_event_proposal_create_saves_draft_with_softcopy_and_evaluation_form(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalCreate::class)
            ->set('title', 'Livewire Created PPA Event')
            ->set('proposalCategory', 'activity')
            ->set('startDate', today()->addDays(10)->toDateString())
            ->set('endDate', today()->addDays(10)->toDateString())
            ->set('locationType', 'on-campus')
            ->set('locationDetails', 'USM Gymnasium')
            ->set('implementingOffice', 'PSITS')
            ->set('targetParticipants', 'All BSIS students')
            ->set('sourceOfFund', 'Organization fund')
            ->set('budgetCost', '2500')
            ->set('resolutionNumber', 'RES-LW-2026')
            ->set('hardcopySubmitted', true)
            ->set('headOrganizationSigned', true)
            ->set('adviserSigned', true)
            ->set('proposalDocument', UploadedFile::fake()->create('livewire-official-ppa.docx', 128, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->set('eventDays', [[
                'date' => today()->addDays(10)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]])
            ->set('evaluationQuestions', $this->customEvaluationQuestions())
            ->call('createProposal')
            ->assertRedirect(route('dashboard.events'));

        $event = Event::where('title', 'Livewire Created PPA Event')->first();

        $this->assertNotNull($event);
        $this->assertSame($h['org']->id, $event->organization_id);
        $this->assertSame('draft', $event->status);
        $this->assertSame('student_affairs', $event->event_domain);
        $this->assertFalse($event->hardcopy_submitted);
        $this->assertFalse($event->head_organization_signed);
        $this->assertFalse($event->adviser_signed);
        $this->assertSame('livewire-official-ppa.docx', $event->proposal_document_original_name);
        $this->assertNotNull($event->evaluation_form_version_id);
        $this->assertDatabaseHas('event_days', [
            'event_id' => $event->id,
            'day_number' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'organization_id' => $h['org']->id,
        ]);
        $this->assertDatabaseHas('evaluation_form_versions', [
            'event_id' => $event->id,
            'title' => 'Evaluation - Livewire Created PPA Event',
        ]);
        Storage::disk('local')->assertExists($event->proposal_document_path);
    }

    public function test_livewire_event_proposal_create_saves_canonical_packet_and_syncs_event_fields(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $eventDate = today()->addDays(12)->toDateString();

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalCreate::class)
            ->set('title', 'Canonical Packet Assembly')
            ->set('proposalCategory', 'project')
            ->set('activityRole', CanonicalPpaPacket::ROLE_CO_ORGANIZER)
            ->set('startDate', $eventDate)
            ->set('endDate', $eventDate)
            ->set('locationType', 'off-campus')
            ->set('locationDetails', 'Kabacan Training Center')
            ->set('implementingOffice', 'PSITS')
            ->set('collaboratingOffice', 'Guidance Office')
            ->set('targetParticipants', '120 BSIS students')
            ->set('sourceOfFund', 'Organization fund')
            ->set('budgetCost', '3750')
            ->set('proponentName', 'Jane Secretary')
            ->set('proponentPosition', 'Secretary')
            ->set('proponentOrganization', 'PSITS')
            ->set('proponentEmail', 'jane.secretary@usm.edu.ph')
            ->set('proponentContactNumber', '09171234567')
            ->set('rationale', 'Student leadership continuity needs a structured activity.')
            ->set('generalObjective', 'Prepare students for responsible event operations.')
            ->set('specificObjectives', "Orient committee members\nValidate activity logistics")
            ->set('methodology', 'Briefing, workshop, and guided output review.')
            ->set('majorActivities', "Committee briefing\nOutput review")
            ->set('expectedOutput', 'A validated activity operations checklist.')
            ->set('eventDays', [[
                'date' => $eventDate,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]])
            ->set('evaluationQuestions', $this->customEvaluationQuestions())
            ->call('createProposal')
            ->assertRedirect(route('dashboard.events'));

        $event = Event::with('canonicalPpaPacket')->where('title', 'Canonical Packet Assembly')->firstOrFail();
        $packet = $event->canonicalPpaPacket;

        $this->assertNotNull($packet);
        $this->assertSame(CanonicalPpaPacket::ROLE_CO_ORGANIZER, $packet->activity_role);
        $this->assertSame($event->title, $packet->title);
        $this->assertSame($event->proposal_category, $packet->proposal_category);
        $this->assertSame($event->start_date->toDateString(), $packet->start_date->toDateString());
        $this->assertSame($event->end_date->toDateString(), $packet->end_date->toDateString());
        $this->assertSame($event->location_type, $packet->location_type);
        $this->assertSame($event->location_details, $packet->location_details);
        $this->assertSame($event->target_demographics['implementing_office'], $packet->implementing_office);
        $this->assertSame($event->target_demographics['collaborating_office'], $packet->collaborating_office);
        $this->assertSame($event->budget_allocations['source_of_fund'], $packet->source_of_fund);
        $this->assertSame('3750.00', $packet->budget_cost);
        $this->assertSame('Jane Secretary', $packet->proponent_name);
        $this->assertSame(['Orient committee members', 'Validate activity logistics'], $packet->specific_objectives);
        $this->assertSame(['Committee briefing', 'Output review'], $packet->major_activities);
        $this->assertSame(Event::STATUS_DRAFT, $event->status);
    }

    public function test_livewire_event_proposal_create_generates_daily_attendance_schedule(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        Livewire::actingAs($societyAdmin)
            ->test(EventProposalCreate::class)
            ->set('startDate', '2026-07-17')
            ->set('eventDays.0.start_time', '08:30')
            ->set('eventDays.0.end_time', '11:00')
            ->set('endDate', '2026-07-19')
            ->assertSet('eventDays.0.date', '2026-07-17')
            ->assertSet('eventDays.1.date', '2026-07-18')
            ->assertSet('eventDays.2.date', '2026-07-19')
            ->assertSet('eventDays.1.start_time', '08:30')
            ->assertSet('eventDays.2.end_time', '11:00')
            ->assertSee('Daily attendance schedule')
            ->assertSee('Same time for all days')
            ->assertSee('Save Draft Proposal');
    }

    public function test_livewire_event_proposal_create_allows_missing_softcopy_but_validates_questions_and_day_range(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);

        $component = Livewire::actingAs($societyAdmin)
            ->test(EventProposalCreate::class)
            ->set('title', 'Invalid Livewire PPA Event')
            ->set('proposalCategory', 'activity')
            ->set('startDate', '2026-07-17')
            ->set('endDate', '2026-07-17')
            ->set('locationType', 'on-campus')
            ->set('eventDays', [[
                'date' => '2026-07-17',
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]])
            ->set('evaluationQuestions', [])
            ->call('createProposal')
            ->assertHasErrors(['evaluationQuestions']);

        $component
            ->set('evaluationQuestions', [])
            ->call('createProposal')
            ->assertHasErrors(['evaluationQuestions']);

        $component
            ->set('evaluationQuestions', $this->customEvaluationQuestions())
            ->set('eventDays', [[
                'date' => '2026-01-17',
                'start_time' => '20:30',
                'end_time' => '23:00',
            ]])
            ->call('createProposal')
            ->assertHasErrors(['eventDays.0.date']);

        $component
            ->set('eventDays', [[
                'date' => '2026-07-17',
                'start_time' => '20:30',
                'end_time' => '08:30',
            ]])
            ->call('createProposal')
            ->assertHasErrors(['eventDays.0.end_time'])
            ->assertSee('Day 1 end time must be later than the start time.');

        $this->assertDatabaseMissing('events', [
            'title' => 'Invalid Livewire PPA Event',
        ]);
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

    public function test_complete_online_packet_submits_without_legacy_softcopy_or_hardcopy(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $eventDate = today()->addDays(14)->toDateString();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), [
                'title' => 'Generated Online PPA',
                'proposal_category' => 'activity',
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'start_date' => $eventDate,
                'end_date' => $eventDate,
                'location_type' => 'online',
                'location_details' => 'Google Meet room for USM leadership training',
                'implementing_office' => 'PSITS',
                'target_participants' => '120 BSIS students',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 1500,
                'proponent_name' => 'Jane Secretary',
                'proponent_position' => 'Secretary',
                'proponent_organization' => 'PSITS',
                'proponent_email' => 'jane.secretary@usm.edu.ph',
                'proponent_contact_number' => '09170000000',
                'rationale' => 'Students need an accessible leadership activity.',
                'general_objective' => 'Deliver leadership training through one generated packet.',
                'specific_objectives' => "Orient officers\nValidate online activity logistics",
                'methodology' => 'Online lecture, breakout discussion, and output review.',
                'expected_output' => 'Completed online leadership training.',
                'event_days' => [[
                    'date' => $eventDate,
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
                'evaluation_questions' => $this->customEvaluationQuestions(),
            ])
            ->assertRedirect(route('dashboard.events'));

        $event = Event::with('canonicalPpaPacket')->where('title', 'Generated Online PPA')->firstOrFail();
        $this->assertNull($event->proposal_document_path);
        $this->assertFalse($event->hardcopy_submitted);
        $this->assertSame('online', $event->canonicalPpaPacket->location_type);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $event))
            ->assertSessionHas('success');

        $this->assertSame(Event::STATUS_SUBMITTED, $event->fresh()->status);
    }

    public function test_off_campus_packet_requires_evidence_before_submission(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $eventDate = today()->addDays(16)->toDateString();

        $payload = [
            'title' => 'Off Campus PPA Packet',
            'proposal_category' => 'activity',
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'start_date' => $eventDate,
            'end_date' => $eventDate,
            'location_type' => 'off-campus',
            'location_details' => 'Kabacan Training Center, Poblacion, Kabacan',
            'venue_confirmation_office' => 'Kabacan Training Center Administration',
            'venue_confirmation_date' => today()->toDateString(),
            'implementing_office' => 'PSITS',
            'target_participants' => '80 BSIS students',
            'source_of_fund' => 'Organization fund',
            'budget_cost' => 2500,
            'proponent_name' => 'Jane Secretary',
            'proponent_position' => 'Secretary',
            'proponent_organization' => 'PSITS',
            'proponent_email' => 'jane.secretary@usm.edu.ph',
            'proponent_contact_number' => '09170000000',
            'rationale' => 'Students need supervised off-campus exposure.',
            'general_objective' => 'Conduct an off-campus learning activity.',
            'specific_objectives' => "Coordinate venue requirements\nComplete safety reminders",
            'methodology' => 'Briefing, travel coordination, and guided activity.',
            'expected_output' => 'Completed off-campus learning activity.',
            'event_days' => [[
                'date' => $eventDate,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => $this->customEvaluationQuestions(),
        ];

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), $payload)
            ->assertRedirect(route('dashboard.events'));

        $event = Event::where('title', 'Off Campus PPA Packet')->firstOrFail();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $event))
            ->assertSessionHas('error');

        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), array_merge($payload, [
                'title' => 'Off Campus PPA Packet With Evidence',
                'venue_confirmation_document' => UploadedFile::fake()->create('confirmation.pdf', 128, 'application/pdf'),
            ]))
            ->assertRedirect(route('dashboard.events'));

        $completeEvent = Event::with('canonicalPpaPacket')->where('title', 'Off Campus PPA Packet With Evidence')->firstOrFail();
        $this->assertSame('confirmation.pdf', $completeEvent->canonicalPpaPacket->venue_confirmation_document_original_name);

        $packetEvidencePath = $completeEvent->canonicalPpaPacket->venue_confirmation_document_path;
        $packetEvidenceName = $completeEvent->canonicalPpaPacket->venue_confirmation_document_original_name;

        $completeEvent->canonicalPpaPacket->update([
            'venue_confirmation_document_path' => null,
            'venue_confirmation_document_original_name' => null,
        ]);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $completeEvent))
            ->assertSessionHas('error');

        $this->assertSame(Event::STATUS_DRAFT, $completeEvent->fresh()->status);

        $completeEvent->canonicalPpaPacket()->update([
            'venue_confirmation_document_path' => $packetEvidencePath,
            'venue_confirmation_document_original_name' => $packetEvidenceName,
        ]);

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.submit', $completeEvent))
            ->assertSessionHas('success');

        $this->assertSame(Event::STATUS_SUBMITTED, $completeEvent->fresh()->status);
    }

    public function test_off_campus_packet_rejects_campus_venue_reference(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $venue = Venue::create([
            'name' => 'USM Auditorium',
            'campus_location' => 'Main Campus',
            'managing_office' => 'Campus Facilities Office',
            'is_active' => true,
        ]);
        $eventDate = today()->addDays(18)->toDateString();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.events.create'), [
                'title' => 'Invalid Off Campus Venue Packet',
                'proposal_category' => 'activity',
                'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
                'start_date' => $eventDate,
                'end_date' => $eventDate,
                'location_type' => 'off-campus',
                'location_details' => 'Kabacan Training Center, Poblacion, Kabacan',
                'venue_id' => $venue->id,
                'implementing_office' => 'PSITS',
                'target_participants' => '80 BSIS students',
                'source_of_fund' => 'Organization fund',
                'budget_cost' => 2500,
                'proponent_name' => 'Jane Secretary',
                'proponent_position' => 'Secretary',
                'proponent_organization' => 'PSITS',
                'proponent_email' => 'jane.secretary@usm.edu.ph',
                'proponent_contact_number' => '09170000000',
                'rationale' => 'Students need supervised off-campus exposure.',
                'general_objective' => 'Conduct an off-campus learning activity.',
                'specific_objectives' => 'Coordinate venue requirements',
                'methodology' => 'Briefing and guided activity.',
                'expected_output' => 'Completed off-campus learning activity.',
                'event_days' => [[
                    'date' => $eventDate,
                    'start_time' => '08:00',
                    'end_time' => '12:00',
                ]],
                'evaluation_questions' => $this->customEvaluationQuestions(),
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('events', [
            'title' => 'Invalid Off Campus Venue Packet',
        ]);
    }

    public function test_generated_proposal_and_permit_previews_render_from_canonical_packet(): void
    {
        $h = $this->createHierarchy();
        $societyAdmin = $this->createAdmin('Society Admin', $h['org']);
        $event = Event::factory()->for($h['org'], 'organization')->create([
            'title' => 'Preview Packet Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'start_date' => today()->addDays(12),
            'end_date' => today()->addDays(12),
            'location_type' => 'online',
            'location_details' => 'Zoom room generated from packet',
        ]);
        CanonicalPpaPacket::factory()->for($event)->create([
            'title' => 'Preview Packet Event',
            'proposal_category' => 'activity',
            'location_type' => 'online',
            'location_details' => 'Zoom room generated from packet',
            'rationale' => 'Preview renders from the canonical source.',
            'general_objective' => 'Show packet-generated proposal and permit.',
            'specific_objectives' => ['Render proposal preview', 'Render permit preview'],
            'methodology' => 'Use the packet instead of an uploaded form.',
            'program_activities' => [[
                'date' => today()->addDays(12)->toDateString(),
                'time' => '08:00 - 12:00',
                'activity' => 'Generated preview walkthrough',
                'person_in_charge' => 'Jane Secretary',
            ]],
            'expected_output' => 'Rendered packet previews.',
            'beneficiaries' => 'BSIS students',
            'budget_breakdown' => [['item' => 'Overall total', 'amount' => '1500.00']],
        ]);

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.events.proposal-preview', $event))
            ->assertOk()
            ->assertSee('Generated from Canonical PPA Packet')
            ->assertSee('Preview renders from the canonical source.')
            ->assertSee('Approval / Signatories')
            ->assertSee('Head of Organization')
            ->assertSee('Render proposal preview');

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.events.permit-preview', $event))
            ->assertOk()
            ->assertSee('Student Activity Permit Preview')
            ->assertSee('Online platform or link')
            ->assertSee('Zoom room generated from packet');
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

        $event = Event::create([
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
            ->assertSee('Audience Builder Render Event');

        Livewire::actingAs($organizerAdmin)
            ->test(EventsWorkspace::class)
            ->call('openModal', 'participant-scopes', $event->id)
            ->assertSee('All can participate')
            ->assertSee('College')
            ->assertSee('Specific org')
            ->assertSee('wire:submit="addParticipantScopes"', false)
            ->assertSee('participantAudienceBuilder', false)
            ->assertSee("\$wire.entangle('audienceMode')", false)
            ->assertSee('x-on:click="setAudienceMode(\'all\')"', false)
            ->assertSee('x-model.debounce.150ms="organizationQuery"', false)
            ->assertSee('x-model="selectedOrganizationIds"', false)
            ->assertSee('Search org acronym, name, or College')
            ->assertDontSee('wire:click="setAudienceMode', false)
            ->assertDontSee('wire:model.live.debounce.250ms="organizationQuery"', false)
            ->assertDontSee('wire:model.live="selectedOrganizationIds"', false)
            ->assertDontSee('name="audience_mode"', false)
            ->assertDontSee('name="college_ids[]"', false)
            ->assertDontSee('name="organization_ids[]"', false);
    }

    public function test_attendance_rules_modal_renders_cutoff_suggestions(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Cutoff Suggestions Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
            'event_late_cutoff_min' => null,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addDays(5),
            'start_time' => '08:30',
            'end_time' => '12:00',
        ]);

        $this->actingAs($organizerAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Cutoff Suggestions Event');

        Livewire::actingAs($organizerAdmin)
            ->test(EventsWorkspace::class)
            ->call('openModal', 'attendance-rules', $event->id)
            ->assertSee('Current rule')
            ->assertSee('Participant fallback')
            ->assertSee('wire:submit="saveEventCutoff"', false)
            ->assertSee('data-cutoff-value="10"', false)
            ->assertSee('data-cutoff-value="15"', false)
            ->assertSee('data-cutoff-value="30"', false)
            ->assertSee('Standard');
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
        $otherDepartment = Department::create([
            'college_id' => $otherCollege->id,
            'name' => 'Department of Biology',
            'code' => 'BIO',
        ]);
        $otherProgram = Program::create([
            'department_id' => $otherDepartment->id,
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
            'department_id' => $otherDepartment->id,
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

    public function test_participant_org_can_set_scope_cutoff_only_when_event_cutoff_is_unset(): void
    {
        $h = $this->createHierarchy();
        $participantOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Scope Cutoff Society',
            'acronym' => 'SCS',
            'type' => 'society',
        ]);
        $participantAdmin = $this->createAdmin('Society Admin', $participantOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Scope Policy Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(3),
            'end_date' => today()->addDays(3),
            'event_late_cutoff_min' => null,
        ]);
        $scope = $this->createParticipantScope($event, $participantOrg, EventParticipationScope::PPA_DRAFT);

        $this->actingAs($participantAdmin)
            ->post(route('dashboard.events.participant-scopes.attendance-policy', [$event->id, $scope->id]), [
                'late_cutoff_min' => 12,
            ])
            ->assertSessionHas('success', 'Participant late cutoff updated.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'late_cutoff_min' => 12,
        ]);

        $event->update(['event_late_cutoff_min' => 30]);

        $this->actingAs($participantAdmin)
            ->post(route('dashboard.events.participant-scopes.attendance-policy', [$event->id, $scope->id]), [
                'late_cutoff_min' => 5,
            ])
            ->assertSessionHas('error', 'This event already has an organizer late cutoff. Participant scope cutoffs are available only when the organizer leaves the event cutoff unset.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'late_cutoff_min' => 12,
        ]);
    }

    public function test_organizer_can_update_attendance_rules_before_event_starts(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Editable Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
            'event_late_cutoff_min' => null,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addDays(5),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.attendance-policy', $event->id), [
                'event_late_cutoff_min' => 25,
            ])
            ->assertSessionHas('success', 'Attendance rules updated.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => 25,
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.attendance-policy', $event->id), [
                'event_late_cutoff_min' => null,
            ])
            ->assertSessionHas('success', 'Attendance rules updated.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => null,
        ]);
    }

    public function test_livewire_attendance_rules_updates_event_cutoff_without_page_reload(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Editable Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
            'event_late_cutoff_min' => null,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addDays(5),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        Livewire::actingAs($organizerAdmin)
            ->test(EventAttendanceRules::class, ['eventId' => $event->id])
            ->assertSee('Participant fallback')
            ->call('setEventPreset', 25)
            ->assertSet('eventLateCutoffMin', '25')
            ->call('saveEventCutoff')
            ->assertSet('notice', 'Attendance rules updated.')
            ->assertSee('25 min after start')
            ->call('setEventPreset', null)
            ->call('saveEventCutoff')
            ->assertSet('notice', 'Attendance rules updated.')
            ->assertSee('Participant fallback');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => null,
        ]);
    }

    public function test_college_visible_admin_cannot_update_another_organizers_attendance_rules(): void
    {
        $h = $this->createHierarchy();
        $lsgOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'CEIT LSG',
            'acronym' => 'CEIT-LSG',
            'type' => 'lsg',
        ]);
        $lsgAdmin = $this->createAdmin('LSG Admin', $lsgOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Another Organizer Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
            'event_late_cutoff_min' => null,
        ]);

        $this->actingAs($lsgAdmin)
            ->post(route('dashboard.events.attendance-policy', $event->id), [
                'event_late_cutoff_min' => 25,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => null,
        ]);
    }

    public function test_attendance_rules_remain_editable_before_start_even_with_existing_scans(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        Carbon::setTestNow(Carbon::parse('2026-07-17 07:10:00', 'Asia/Manila'));

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Pre Start Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'end_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'event_late_cutoff_min' => 15,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'start_time' => '08:30',
            'end_time' => '12:00',
        ]);

        RawScan::create([
            'event_id' => $event->id,
            'scan_type' => 'time_in',
            'scanned_at' => now(),
            'server_received_at' => now(),
            'device_id' => 'rules-lock-device',
            'manual_entry' => false,
            'dedup_key' => 'rules-lock-scan',
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.attendance-policy', $event->id), [
                'event_late_cutoff_min' => 30,
            ])
            ->assertSessionHas('success', 'Attendance rules updated.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => 30,
        ]);

        Carbon::setTestNow();
    }

    public function test_attendance_rules_use_event_start_date_when_event_day_date_is_outside_range(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        Carbon::setTestNow(Carbon::parse('2026-07-17 19:10:00', 'Asia/Manila'));

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Mismatched Stored Date Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'end_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'event_late_cutoff_min' => 15,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::parse('2026-01-17', 'Asia/Manila'),
            'start_time' => '20:30',
            'end_time' => '23:00',
        ]);

        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.attendance-policy', $event->id), [
                'event_late_cutoff_min' => 30,
            ])
            ->assertSessionHas('success', 'Attendance rules updated.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => 30,
        ]);

        Carbon::setTestNow();
    }

    public function test_livewire_attendance_rules_updates_participant_scope_cutoff_and_dispatches_reprocessing(): void
    {
        Bus::fake();

        $h = $this->createHierarchy();
        $participantOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Scope Cutoff Society',
            'acronym' => 'LSCS',
            'type' => 'society',
        ]);
        $participantAdmin = $this->createAdmin('Society Admin', $participantOrg);
        $student = $this->createStudent($participantOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Scope Policy Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(3),
            'end_date' => today()->addDays(3),
            'event_late_cutoff_min' => null,
        ]);
        $scope = $this->createParticipantScope($event, $participantOrg, EventParticipationScope::PPA_DRAFT);

        RawScan::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'student_id' => $student->id,
            'scan_type' => 'time_in',
            'scanned_at' => now(),
            'server_received_at' => now(),
            'device_id' => 'livewire-scope-rules-device',
            'manual_entry' => false,
            'dedup_key' => 'livewire-scope-rules-scan',
        ]);

        Livewire::actingAs($participantAdmin)
            ->test(EventAttendanceRules::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->assertSee('Default 15 min fallback')
            ->call('setScopePreset', $scope->id, 12)
            ->assertSet("scopeLateCutoffMins.{$scope->id}", '12')
            ->call('saveScopeCutoff', $scope->id)
            ->assertSet('notice', 'Participant late cutoff updated.')
            ->assertSee('12 min scope fallback');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'late_cutoff_min' => 12,
        ]);

        Bus::assertDispatched(ResolveAttendanceJob::class);
    }

    public function test_attendance_rules_lock_after_start(): void
    {
        $h = $this->createHierarchy();
        $participantOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Locked Scope Society',
            'acronym' => 'LSS',
            'type' => 'society',
        ]);
        $participantAdmin = $this->createAdmin('Society Admin', $participantOrg);
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:31:00', 'Asia/Manila'));

        $startedEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Started Scope Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'end_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'event_late_cutoff_min' => null,
        ]);
        EventDay::create([
            'event_id' => $startedEvent->id,
            'day_number' => 1,
            'date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'start_time' => '08:30',
            'end_time' => '12:00',
        ]);
        $scope = $this->createParticipantScope($startedEvent, $participantOrg, EventParticipationScope::PPA_DRAFT);

        $this->actingAs($participantAdmin)
            ->post(route('dashboard.events.participant-scopes.attendance-policy', [$startedEvent->id, $scope->id]), [
                'late_cutoff_min' => 20,
            ])
            ->assertSessionHas('error', 'Attendance rules are locked after the event starts.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'late_cutoff_min' => null,
        ]);

        Carbon::setTestNow();
    }

    public function test_livewire_attendance_rules_lock_after_start(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:31:00', 'Asia/Manila'));

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Locked Rules Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'end_date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'event_late_cutoff_min' => null,
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => Carbon::parse('2026-07-17', 'Asia/Manila'),
            'start_time' => '08:30',
            'end_time' => '12:00',
        ]);

        Livewire::actingAs($organizerAdmin)
            ->test(EventAttendanceRules::class, ['eventId' => $event->id])
            ->set('eventLateCutoffMin', '20')
            ->call('saveEventCutoff')
            ->assertSet('errorNotice', 'Attendance rules are locked after the event starts.');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'event_late_cutoff_min' => null,
        ]);

        Carbon::setTestNow();
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
        $this->confirmVenueForEvent($event, 'Deferred Invitation Venue');

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

        $notification = $osa->fresh()->notifications()
            ->get()
            ->first(fn ($notification): bool => ($notification->data['kind'] ?? null) === 'participant_scope_submitted');

        $this->assertNotNull($notification);
        $this->assertSame('participant_scope_submitted', $notification->data['kind']);
        $this->assertTrue($notification->data['needs_action']);
    }

    public function test_livewire_participant_scope_register_acknowledges_invitation(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Acknowledging Partner',
            'acronym' => 'LAP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Acknowledgement Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        Livewire::actingAs($partnerAdmin)
            ->test(EventParticipantScopes::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->assertSee('Acknowledge Invitation')
            ->call('acknowledgeInvitation', $scope->id)
            ->assertSet('notice', 'Participant invitation acknowledged.')
            ->assertDispatched('notifications-updated');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'invitation_acknowledged_by' => $partnerAdmin->id,
        ]);

        $receipt = $organizerAdmin->fresh()->notifications()->first();

        $this->assertNotNull($receipt);
        $this->assertSame('participant_scope_acknowledged', $receipt->data['kind']);
    }

    public function test_livewire_participant_scope_register_submits_own_ppa_upload(): void
    {
        Storage::fake('local');

        $h = $this->createHierarchy();
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Submit Partner',
            'acronym' => 'LSP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire PPA Submission Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        Livewire::actingAs($partnerAdmin)
            ->test(EventParticipantScopes::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->set('ppaSoftcopies.'.$scope->id, UploadedFile::fake()->create('livewire-partner-ppa.pdf', 128, 'application/pdf'))
            ->call('submitParticipantPpa', $scope->id)
            ->assertSet('notice', 'Participant PPA submitted for OSA review.')
            ->assertDispatched('notifications-updated');

        $scope->refresh();

        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->ppa_status);
        $this->assertSame($partnerAdmin->id, $scope->submitted_by);
        $this->assertFalse($scope->hardcopy_submitted);
        $this->assertFalse($scope->org_head_signed);
        $this->assertFalse($scope->adviser_signed);
        $this->assertNotNull($scope->ppa_softcopy_path);
    }

    public function test_livewire_participant_scope_register_handles_osa_review_decisions(): void
    {
        $h = $this->createHierarchy();
        $osa = $this->createAdmin('Super Admin (OSA)');
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Review Partner',
            'acronym' => 'LRP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire OSA Review Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg, EventParticipationScope::PPA_SUBMITTED);
        $scope->update([
            'ppa_softcopy_path' => 'participant-ppa-documents/livewire-review.pdf',
            'ppa_softcopy_original_name' => 'livewire-review.pdf',
            'hardcopy_submitted' => true,
        ]);

        Livewire::actingAs($osa)
            ->test(EventParticipantScopes::class, ['eventId' => $event->id])
            ->call('reviewParticipantPpa', $scope->id)
            ->assertSet('notice', 'Participant PPA is now under review.')
            ->call('approveParticipantPpa', $scope->id)
            ->assertSet('notice', 'Participant PPA approved. Scoped scanner access is now available.')
            ->assertDispatched('notifications-updated');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => $osa->id,
        ]);

        $rejectedPartnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Rejection Partner',
            'acronym' => 'LRJ',
            'type' => 'society',
        ]);
        $rejectedPartnerOrg->programs()->attach($h['program']->id);
        $rejectedScope = $this->createParticipantScope($event, $rejectedPartnerOrg, EventParticipationScope::PPA_UNDER_REVIEW);
        $rejectedScope->update([
            'ppa_softcopy_path' => 'participant-ppa-documents/livewire-reject.pdf',
            'ppa_softcopy_original_name' => 'livewire-reject.pdf',
            'hardcopy_submitted' => true,
        ]);

        Livewire::actingAs($osa)
            ->test(EventParticipantScopes::class, ['eventId' => $event->id])
            ->set('reviewRemarks.'.$rejectedScope->id, 'Missing adviser signature.')
            ->call('rejectParticipantPpa', $rejectedScope->id)
            ->assertSet('notice', 'Participant PPA rejected.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $rejectedScope->id,
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'review_remarks' => 'Missing adviser signature.',
        ]);
    }

    public function test_livewire_participant_scope_revision_is_resubmittable_but_rejection_is_final(): void
    {
        $h = $this->createHierarchy();
        $osa = $this->createAdmin('Super Admin (OSA)');
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Revision Partner',
            'acronym' => 'RVP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);
        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Participant Revision Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_SUBMITTED,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg, EventParticipationScope::PPA_SUBMITTED);
        $scope->update([
            'ppa_softcopy_path' => 'participant-ppa-documents/original.pdf',
            'ppa_softcopy_original_name' => 'original.pdf',
            'hardcopy_submitted' => true,
        ]);

        Livewire::actingAs($osa)
            ->test(EventParticipantScopes::class, ['eventId' => $event->id])
            ->set('reviewRemarks.'.$scope->id, 'Please attach the corrected participant PPA.')
            ->call('requestParticipantPpaRevision', $scope->id)
            ->assertSet('notice', 'Participant PPA returned for revision.')
            ->assertDispatched('notifications-updated');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
            'review_remarks' => 'Please attach the corrected participant PPA.',
        ]);

        Livewire::actingAs($partnerAdmin)
            ->test(EventParticipantScopes::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->call('submitParticipantPpa', $scope->id)
            ->assertSet('notice', 'Participant PPA submitted for OSA review.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'id' => $scope->id,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'review_remarks' => null,
            'submitted_by' => $partnerAdmin->id,
        ]);

        $scope->refresh()->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'review_remarks' => 'Denied by OSA.',
        ]);

        Livewire::actingAs($partnerAdmin)
            ->test(EventParticipantScopes::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->call('submitParticipantPpa', $scope->id)
            ->assertSet('errorNotice', 'Only draft or revision-requested participant PPAs can be submitted.');

        $this->assertSame(EventParticipationScope::PPA_REJECTED, $scope->fresh()->ppa_status);
    }

    public function test_livewire_participant_scope_builder_creates_organization_scopes(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $partnerOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Builder Partner',
            'acronym' => 'LBP',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($h['program']->id);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Builder Organization Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);

        Livewire::actingAs($organizerAdmin)
            ->test(EventParticipantScopes::class, ['eventId' => $event->id])
            ->set('selectedOrganizationIds', [$partnerOrg->id, $h['org']->id])
            ->call('addParticipantScopes')
            ->assertSet('notice', 'Participant scope added. Organizer audience was skipped because it is already included.')
            ->assertDispatched('participant-scopes-updated')
            ->assertDispatched('notifications-updated');

        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'organization_id' => $partnerOrg->id,
            'participant_type' => 'society',
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
        ]);
        $this->assertDatabaseMissing('event_participation_scopes', [
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'organization_id' => $h['org']->id,
        ]);

        Livewire::actingAs($organizerAdmin)
            ->test(EventParticipantScopes::class, ['eventId' => $event->id])
            ->set('selectedOrganizationIds', [$partnerOrg->id])
            ->call('addParticipantScopes')
            ->assertSet('errorNotice', 'Every selected audience already has an active participant scope for the event.');
    }

    public function test_livewire_participant_scope_builder_creates_college_and_all_scopes(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin('Society Admin', $h['org']);
        $otherCollege = College::create([
            'university_id' => $h['uni']->id,
            'name' => 'College of Livewire Arts',
            'code' => 'CLA',
        ]);

        $collegeEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Builder College Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(6),
            'end_date' => today()->addDays(6),
        ]);

        Livewire::actingAs($organizerAdmin)
            ->test(EventParticipantScopes::class, ['eventId' => $collegeEvent->id])
            ->call('setAudienceMode', 'college')
            ->set('selectedCollegeIds', [$h['college']->id, $otherCollege->id])
            ->set('captureMode', 'central_gate')
            ->set('ppaRequired', false)
            ->call('addParticipantScopes')
            ->assertSet('notice', '2 participant scopes added.');

        $this->assertDatabaseHas('event_participation_scopes', [
            'event_id' => $collegeEvent->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'college',
            'organization_id' => null,
            'college_id' => $h['college']->id,
            'ppa_status' => EventParticipationScope::PPA_NOT_REQUIRED,
            'capture_mode' => 'central_gate',
        ]);

        $allEvent = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Builder All Colleges Event',
            'proposal_category' => 'activity',
            'status' => 'submitted',
            'start_date' => today()->addDays(7),
            'end_date' => today()->addDays(7),
        ]);
        $expectedCollegeScopes = College::count();

        Livewire::actingAs($organizerAdmin)
            ->test(EventParticipantScopes::class, ['eventId' => $allEvent->id])
            ->call('setAudienceMode', 'all')
            ->set('captureMode', 'central_gate')
            ->set('ppaRequired', false)
            ->call('addParticipantScopes')
            ->assertSet('notice', "{$expectedCollegeScopes} participant scopes added.");

        $this->assertSame($expectedCollegeScopes, EventParticipationScope::query()
            ->where('event_id', $allEvent->id)
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('participant_type', 'college')
            ->whereNull('organization_id')
            ->count());
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

    public function test_livewire_scanner_access_initializes_default_scope_only_when_mounted(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Scanner Default Scope Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_APPROVED,
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        Livewire::actingAs($admin)
            ->test(EventScannerAccess::class, ['eventId' => $event->id])
            ->assertSet('scopeId', $scope->id)
            ->set('scopeId', null)
            ->refresh()
            ->assertSet('scopeId', null);
    }

    public function test_livewire_scanner_access_assigns_and_revokes_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $scanner = $this->createStudent($h['org']);
        $scanner->update([
            'student_id_number' => '2024-04001',
            'qr_code_value' => 'QR-LIVEWIRE-SCANNER',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Scanner Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $component = Livewire::actingAs($admin)
            ->test(EventScannerAccess::class, ['eventId' => $event->id])
            ->set('scopeId', $scope->id)
            ->call('addScanner', $scanner->id)
            ->set('label', 'Main Gate')
            ->call('assignScanners')
            ->assertSee('Scanner assignment created.');

        $assignment = EventScannerAssignment::where('event_id', $event->id)
            ->where('scanner_user_id', $scanner->id)
            ->firstOrFail();

        $this->assertSame(EventScannerAssignment::STATUS_ACTIVE, $assignment->status);

        $component
            ->call('revokeAssignment', $assignment->id)
            ->assertSee('Scanner assignment revoked.');

        $this->assertDatabaseHas('event_scanner_assignments', [
            'id' => $assignment->id,
            'status' => EventScannerAssignment::STATUS_REVOKED,
            'revoked_by_user_id' => $admin->id,
        ]);
    }

    public function test_livewire_scanner_search_requires_query_and_is_case_insensitive(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $scanner = $this->createStudent($h['org']);
        $scanner->update([
            'name' => 'Mixed Case Scanner',
            'student_id_number' => '2024-Case-01',
            'qr_code_value' => 'QR-MIXED-CASE-SCANNER',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Scanner Search Case Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        Livewire::actingAs($admin)
            ->test(EventScannerAccess::class, ['eventId' => $event->id])
            ->set('scopeId', $scope->id)
            ->assertDontSee('Mixed Case Scanner')
            ->set('scannerQuery', 'mixed case')
            ->assertSee('Mixed Case Scanner')
            ->set('scannerQuery', 'MIXED CASE')
            ->assertSee('Mixed Case Scanner');
    }

    public function test_livewire_scanner_access_revokes_session_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $scanner = $this->createStudent($h['org']);
        $scanner->update([
            'student_id_number' => '2024-04002',
            'qr_code_value' => 'QR-LIVEWIRE-SESSION',
        ]);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Session Event',
            'proposal_category' => 'activity',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);
        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);
        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $scanner,
            assignedBy: $admin,
            label: 'Main Gate',
            participationScope: $scope
        );
        [$session] = app(ScannerSessionService::class)->createSession(
            event: $event,
            scannerUser: $scanner,
            issuedBy: $admin,
            label: 'Main Gate',
            revokeExisting: false,
            assignment: $assignment
        );

        Livewire::actingAs($admin)
            ->test(EventScannerAccess::class, ['eventId' => $event->id])
            ->call('revokeSession', $session->id)
            ->assertSee('Scanner session revoked.');

        $this->assertDatabaseHas('scanner_sessions', [
            'id' => $session->id,
            'status' => ScannerSession::STATUS_REVOKED,
            'revoked_by' => $admin->id,
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
            ->assertSee('Legacy Organizer Scope Event');

        Livewire::actingAs($admin)
            ->test(EventsWorkspace::class)
            ->call('openModal', 'scanner-access', $event->id)
            ->assertSee('wire:submit="assignScanners"', false)
            ->assertSee('wire:model.live.debounce.300ms="scannerQuery"', false)
            ->assertSee('scannerSearchFocused', false)
            ->assertSee('x-on:focus="scannerSearchFocused = true"', false)
            ->assertSee('x-on:blur="setTimeout(() => scannerSearchFocused = false, 150)"', false)
            ->assertSee('Search and add scanners')
            ->assertSee('Attendance scope')
            ->assertDontSee('data-scanner-search-url', false)
            ->assertDontSee('x-data="scannerAssignmentPicker()"', false)
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

        Livewire::actingAs($admin)
            ->test(EventsWorkspace::class)
            ->call('openModal', 'scanner-access', $event->id)
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

    public function test_event_export_payload_uses_event_specific_evaluation_questions(): void
    {
        $h = $this->createHierarchy();
        $student = $this->createStudent($h['org']);
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $event = $this->createExportableEvent($h['org'], $student);
        $form = EventEvaluationForm::createForEvent($event, $admin, $this->customEvaluationQuestions(), 'Evaluation - Custom Export');

        $event->evaluations()->update([
            'evaluation_form_version_id' => $form->id,
            'section_scores' => [
                'activity_relevance' => 5,
                'facilitator_quality' => 4,
            ],
        ]);

        $payload = EventExportData::build($event->fresh(), $admin);

        $this->assertContains('Activity relevance', $payload['evaluation_rows'][0]);
        $this->assertContains('Facilitator quality', $payload['evaluation_rows'][0]);
        $this->assertSame('5', $payload['evaluation_rows'][1][7]);
        $this->assertSame('4', $payload['evaluation_rows'][1][8]);
    }

    public function test_organization_analytics_filters_students_by_year_level(): void
    {
        $h = $this->createHierarchy();
        $thirdYear = $this->createStudent($h['org']);
        $fourthYear = $this->createStudent($h['org']);
        $fourthYear->update(['year_level' => '4th_year']);
        $this->createMembership($thirdYear, $h, '3rd_year');
        $this->createMembership($fourthYear->fresh(), $h, '4th_year');
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
            ->assertSee('method="GET"', false)
            ->assertSee('name="year_level"', false)
            ->assertSee('value="3rd_year" selected', false)
            ->assertSee('Year: 3rd Year')
            ->assertViewHas('orgMetrics', function (array $orgMetrics) use ($h) {
                return ($orgMetrics[$h['org']->id]['total_members'] ?? null) === 1;
            });

        $this->assertSame('3rd_year', $thirdYear->fresh()->year_level);
    }

    public function test_livewire_organization_analytics_filters_apply_without_full_page_reload(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        Livewire::actingAs($admin)
            ->test(AnalyticsOverviewFilters::class)
            ->set('yearLevel', '3rd_year')
            ->set('eventType', 'activity')
            ->call('applyFilters')
            ->assertSet('collegeId', (string) $h['college']->id)
            ->assertSet('organizationId', (string) $h['org']->id)
            ->assertSet('yearLevel', '3rd_year')
            ->assertSet('eventType', 'activity')
            ->assertSee('Year: 3rd Year')
            ->assertSee('Type: Activity');
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

    public function test_livewire_sentiment_action_dispatches_for_closed_scoped_event(): void
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Closed Sentiment Event',
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

        Livewire::actingAs($admin)
            ->test(EventSentimentAction::class, [
                'eventId' => $event->id,
                'pendingComments' => 1,
            ])
            ->call('analyze')
            ->assertSet('queued', true)
            ->assertSet('notice', 'Queued sentiment analysis for 1 evaluation comment(s).');

        Bus::assertDispatched(AnalyzeEventSentimentsJob::class, 1);
    }

    public function test_livewire_sentiment_action_rejects_open_event(): void
    {
        Bus::fake([AnalyzeEventSentimentsJob::class]);

        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Open Sentiment Event',
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

        Livewire::actingAs($admin)
            ->test(EventSentimentAction::class, [
                'eventId' => $event->id,
                'pendingComments' => 1,
            ])
            ->call('analyze')
            ->assertSet('errorNotice', 'Sentiment analysis is available after the evaluation window closes.');

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

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService
        {
            public function __construct() {}

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

    public function test_livewire_ai_query_is_scoped_like_api_query(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Society Admin', $h['org']);

        Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire Own AI Event',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);

        $otherOrg = Organization::create([
            'college_id' => $h['college']->id,
            'name' => 'Livewire Other AI Society',
            'acronym' => 'LOAI',
            'type' => 'society',
        ]);
        Event::create([
            'organization_id' => $otherOrg->id,
            'title' => 'Livewire Other AI Event',
            'status' => 'completed',
            'start_date' => today()->subDay(),
            'end_date' => today()->subDay(),
        ]);

        $this->app->instance(AiServiceInterface::class, new class extends GeminiService
        {
            public function __construct() {}

            public function parseNaturalLanguageQuery(string $query, User $user): array
            {
                return [
                    'target_table' => 'events',
                    'filters' => [
                        ['field' => 'title', 'operator' => 'LIKE', 'value' => 'Livewire'],
                        ['field' => 'qr_code_value', 'operator' => '=', 'value' => 'unsafe'],
                    ],
                ];
            }
        });

        Livewire::actingAs($admin)
            ->test(AiInsightsQuery::class)
            ->set('query', 'Show Livewire AI events')
            ->call('runQuery')
            ->assertSet('aiError', null)
            ->assertSee('Livewire Own AI Event')
            ->assertDontSee('Livewire Other AI Event')
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
            ->assertRedirect(route('dashboard.admin-users').'?tab=assignments')
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

    public function test_livewire_pending_qr_links_filter_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Super Admin (OSA)');

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Pending Filter Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $manualScan = RawScan::create([
            'event_id' => $event->id,
            'scanner_user_id' => $admin->id,
            'qr_code_value' => 'MANUAL-UNKNOWN-QR',
            'scan_type' => 'time_in',
            'scanned_at' => now(),
            'server_received_at' => now(),
            'device_id' => 'manual-filter-device',
            'manual_entry' => true,
            'dedup_key' => 'manual-filter-test',
        ]);

        PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'raw_scan_id' => $manualScan->id,
            'qr_code_value' => 'MANUAL-UNKNOWN-QR',
            'status' => 'pending',
        ]);

        PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'qr_code_value' => 'SCANNED-UNKNOWN-QR',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)
            ->test(PendingQrLinks::class)
            ->assertSee('MANUAL-UNKNOWN-QR')
            ->assertSee('SCANNED-UNKNOWN-QR')
            ->set('source', 'manual')
            ->assertSee('MANUAL-UNKNOWN-QR')
            ->assertDontSee('SCANNED-UNKNOWN-QR')
            ->set('search', 'filter')
            ->assertSee('Pending Filter Event');
    }

    public function test_livewire_pending_qr_links_resolve_and_flag_without_page_redirect(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin('Super Admin (OSA)');
        $student = $this->createStudent($h['org']);

        $event = Event::create([
            'organization_id' => $h['org']->id,
            'title' => 'Livewire QR Actions',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $resolveLink = PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'qr_code_value' => 'RESOLVE-LIVEWIRE-QR',
            'status' => 'pending',
        ]);

        $flagLink = PendingStudentLink::create([
            'event_id' => $event->id,
            'organization_id' => $h['org']->id,
            'qr_code_value' => 'FLAG-LIVEWIRE-QR',
            'status' => 'pending',
        ]);

        Livewire::actingAs($admin)
            ->test(PendingQrLinks::class)
            ->set('resolveStudentIds.'.$resolveLink->id, $student->id)
            ->set('resolveNotes.'.$resolveLink->id, 'Manual resolution')
            ->call('resolveLink', $resolveLink->id)
            ->assertSee('Pending QR link resolved successfully.')
            ->assertDispatched('pending-qr-link-resolved')
            ->set('flagNotes.'.$flagLink->id, 'Not a student')
            ->call('flagLink', $flagLink->id)
            ->assertSee('Pending QR link flagged.')
            ->assertDispatched('pending-qr-link-flagged');

        $this->assertSame('resolved', $resolveLink->fresh()->status);
        $this->assertSame($student->id, $resolveLink->fresh()->resolved_student_id);
        $this->assertSame('flagged', $flagLink->fresh()->status);
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
