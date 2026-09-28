<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\EventParticipantScopes;
use App\Models\AttendanceRecord;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\EventAnalyticsCache;
use App\Services\EventAnalyticsService;
use App\Services\ParticipantPpaPacketService;
use App\Services\ParticipationScopeService;
use App\Services\ProposalWorkflowTaskService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ParticipantWorkflowGateTest extends TestCase
{
    use RefreshDatabase;

    protected SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake();

        $this->schoolYear = SchoolYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => SchoolYear::STATUS_ACTIVE,
        ]);
    }

    public function test_participant_packet_submission_binds_and_completes_participant_workflow(): void
    {
        $fixture = $this->createWorkflowFixture();
        $templateVersion = $this->createParticipantTemplate();

        app(ParticipantPpaPacketService::class)->submitPacket(
            $fixture['scope'],
            $fixture['participantAdmin'],
            $this->validParticipantPacketData($fixture['participantOrg']),
            UploadedFile::fake()->create('participant-checklist.pdf', 100, 'application/pdf')
        );

        $run = ProposalWorkflowRun::with(['tasks', 'participationScope'])
            ->where('event_participation_scope_id', $fixture['scope']->id)
            ->firstOrFail();

        $this->assertSame($fixture['event']->id, $run->event_id);
        $this->assertSame($fixture['scope']->id, $run->event_participation_scope_id);
        $this->assertSame($templateVersion->id, $run->template_version_id);
        $this->assertNull($fixture['event']->fresh()->workflowRun);
        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $fixture['scope']->fresh()->ppa_status);

        $task = $run->tasks->first();
        $this->assertNotNull($task);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $task->status);
        $this->assertSame($fixture['osa']->id, $task->assigned_user_id);

        $this->actingAs($fixture['osa'])
            ->from(route('dashboard.events'))
            ->post(route('dashboard.events.participant-scopes.approve', [$fixture['event']->id, $fixture['scope']->id]))
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('error', 'Participant PPA review must be completed through the assigned workflow task.');

        Livewire::actingAs($fixture['osa'])
            ->test(EventParticipantScopes::class, ['eventId' => $fixture['event']->id])
            ->call('approveParticipantPpa', $fixture['scope']->id)
            ->assertSet('errorNotice', 'Participant PPA review must be completed through the assigned workflow task.');

        app(ProposalWorkflowTaskService::class)->decide(
            $task,
            $fixture['osa'],
            ProposalWorkflowTask::DECISION_APPROVED
        );

        $this->assertSame(ProposalWorkflowRun::STATUS_APPROVED, $run->fresh()->status);
        $this->assertSame(EventParticipationScope::PPA_APPROVED, $fixture['scope']->fresh()->ppa_status);
        $this->assertNotNull($fixture['scope']->fresh()->approved_at);
    }

    public function test_participant_workflow_approval_controls_scanner_runtime_gates(): void
    {
        $fixture = $this->createWorkflowFixture();
        $this->createParticipantTemplate();

        app(ParticipantPpaPacketService::class)->submitPacket(
            $fixture['scope'],
            $fixture['participantAdmin'],
            $this->validParticipantPacketData($fixture['participantOrg']),
            UploadedFile::fake()->create('participant-checklist.pdf', 100, 'application/pdf')
        );

        $fixture['scope']->forceFill([
            'ppa_softcopy_path' => 'participant-ppa-documents/spoofed.pdf',
            'ppa_softcopy_original_name' => 'spoofed.pdf',
            'hardcopy_submitted' => true,
            'org_head_signed' => true,
            'adviser_signed' => true,
        ])->save();

        try {
            app(ScannerAssignmentService::class)->assign(
                event: $fixture['event'],
                scannerUser: $fixture['scanner'],
                assignedBy: $fixture['participantAdmin'],
                label: 'Participant Gate',
                participationScope: $fixture['scope']->fresh()
            );
            $this->fail('Submitted participant workflow should still block scanner assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_participation_scope_id', $exception->errors());
        }

        $legacyAssignment = EventScannerAssignment::create([
            'event_id' => $fixture['event']->id,
            'event_participation_scope_id' => $fixture['scope']->id,
            'audience_scope_type' => 'society',
            'scanner_user_id' => $fixture['scanner']->id,
            'assigned_by_user_id' => $fixture['participantAdmin']->id,
            'label' => 'Legacy Participant Gate',
            'status' => EventScannerAssignment::STATUS_ACTIVE,
            'max_devices' => 1,
        ]);

        $mobileToken = $fixture['scanner']->createToken('scanner-phone', ['scanner-assignments'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$mobileToken])
            ->postJson("/api/v1/scanner/assignments/{$legacyAssignment->id}/activate", [
                'device_id' => 'participant-device',
            ])
            ->assertStatus(423)
            ->assertJsonFragment(['reason' => 'participant_ppa_locked']);

        [, $scannerToken] = app(ScannerSessionService::class)->createSession(
            event: $fixture['event'],
            scannerUser: $fixture['scanner'],
            issuedBy: $fixture['participantAdmin'],
            label: 'Stale participant device',
            assignment: $legacyAssignment
        );

        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->getJson("/api/v1/events/{$fixture['event']->id}/hydrate")
            ->assertStatus(423)
            ->assertJsonFragment(['reason' => 'participant_ppa_locked']);

        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$scannerToken])
            ->postJson('/api/v1/attendance/sync', [
                'event_id' => $fixture['event']->id,
                'scans' => [[
                    'dedup_key' => 'locked-participant-scan',
                    'scan_type' => 'time_in',
                    'scanned_at' => now()->toIso8601String(),
                    'device_id' => 'participant-device',
                    'manual_entry' => false,
                    'qr_code_value' => $fixture['scanner']->qr_code_value,
                ]],
            ])
            ->assertStatus(423)
            ->assertJsonFragment(['reason' => 'participant_ppa_locked']);

        $task = ProposalWorkflowRun::where('event_participation_scope_id', $fixture['scope']->id)
            ->firstOrFail()
            ->tasks()
            ->firstOrFail();

        app(ProposalWorkflowTaskService::class)->decide(
            $task,
            $fixture['osa'],
            ProposalWorkflowTask::DECISION_APPROVED
        );

        $this->assertTrue($fixture['scope']->fresh()->hardcopy_submitted);

        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $fixture['event'],
            scannerUser: $fixture['scanner'],
            assignedBy: $fixture['participantAdmin'],
            label: 'Participant Gate',
            participationScope: $fixture['scope']->fresh()
        );

        $this->assertSame(EventScannerAssignment::STATUS_ACTIVE, $assignment->status);
    }

    public function test_analytics_rollup_excludes_unapproved_participant_scope_until_workflow_approval(): void
    {
        $fixture = $this->createWorkflowFixture();
        $this->createParticipantTemplate();

        app(ParticipantPpaPacketService::class)->submitPacket(
            $fixture['scope'],
            $fixture['participantAdmin'],
            $this->validParticipantPacketData($fixture['participantOrg']),
            UploadedFile::fake()->create('participant-checklist.pdf', 100, 'application/pdf')
        );

        AttendanceRecord::create([
            'event_id' => $fixture['event']->id,
            'event_participation_scope_id' => $fixture['scope']->id,
            'student_id' => $fixture['scanner']->id,
            'time_in' => Carbon::now(),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'valid' => true,
        ]);

        $summaryBeforeApproval = app(EventAnalyticsService::class)->summary($fixture['event']->fresh());
        $this->assertFalse(
            collect($summaryBeforeApproval['participant_scopes'])->contains('id', $fixture['scope']->id)
        );

        $task = ProposalWorkflowRun::where('event_participation_scope_id', $fixture['scope']->id)
            ->firstOrFail()
            ->tasks()
            ->firstOrFail();

        app(ProposalWorkflowTaskService::class)->decide(
            $task,
            $fixture['osa'],
            ProposalWorkflowTask::DECISION_APPROVED
        );

        app(EventAnalyticsCache::class)->invalidateEvent($fixture['event']->id);

        $summaryAfterApproval = app(EventAnalyticsService::class)->summary($fixture['event']->fresh());
        $participantScope = collect($summaryAfterApproval['participant_scopes'])
            ->firstWhere('id', $fixture['scope']->id);

        $this->assertNotNull($participantScope);
        $this->assertSame(1, $participantScope['expected_audience']);
        $this->assertSame(1, $participantScope['attended']);
    }

    private function createParticipantTemplate(): ProposalProcessTemplateVersion
    {
        $template = ProposalProcessTemplate::factory()->create([
            'name' => 'Participant PPA Workflow',
        ]);

        $version = ProposalProcessTemplateVersion::factory()->for($template, 'template')->create([
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        ProposalProcessStep::factory()->create([
            'template_version_id' => $version->id,
            'step_key' => 'participant_osa_approval',
            'title' => 'Participant OSA Approval',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'participant_ppa',
            'display_order' => 1,
            'is_required' => true,
            'is_gate' => true,
            'condition_config' => [
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
            ],
        ]);

        ProposalTemplateApplicabilityRule::factory()->create([
            'template_version_id' => $version->id,
            'priority' => 100,
            'organizer_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'is_default' => false,
            'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
        ]);

        $version->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $version->fresh();
    }

    /**
     * @return array{
     *     organizerOrg: Organization,
     *     participantOrg: Organization,
     *     participantAdmin: User,
     *     osa: User,
     *     scanner: User,
     *     event: Event,
     *     scope: EventParticipationScope
     * }
     */
    private function createWorkflowFixture(): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);
        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Workflow Tests',
            'code' => 'CWT',
        ]);
        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Department of Workflow Tests',
            'code' => 'DWT',
            'status' => 'active',
        ]);
        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Workflow',
            'code' => 'BSWF',
        ]);

        $organizerOrg = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'Organizer Workflow Society',
            'acronym' => 'OWS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $organizerOrg->programs()->attach($program->id);

        $participantOrg = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'Participant Workflow Society',
            'acronym' => 'PWS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $participantOrg->programs()->attach($program->id);

        $participantAdmin = $this->createAdmin('Society Admin', $participantOrg);
        $osa = $this->createAdmin('Super Admin (OSA)');
        $scanner = $this->createStudent($participantOrg, $program);

        $event = Event::create([
            'organization_id' => $organizerOrg->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => 'Participant Workflow Evidence Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_APPROVED,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today(),
            'end_date' => today(),
            'location_type' => 'on-campus',
            'location_details' => 'Main Campus Ground',
            'hardcopy_submitted' => true,
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'title' => $event->title,
            'location_type' => 'on-campus',
            'location_details' => 'Main Campus Ground',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $organizerScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'school_year_id' => $this->schoolYear->id,
            'parent_scope_id' => $organizerScope->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $participantOrg->id,
            'college_id' => $participantOrg->college_id,
            'participant_name_snapshot' => $participantOrg->name,
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        return compact('organizerOrg', 'participantOrg', 'participantAdmin', 'osa', 'scanner', 'event', 'scope');
    }

    private function createAdmin(string $roleName, ?Organization $organization = null): User
    {
        $user = User::factory()->create([
            'name' => $roleName.' User',
            'email' => strtolower(str_replace([' ', '(', ')'], '', $roleName)).uniqid().'@usm.edu.ph',
            'organization_id' => $organization?->id,
            'college_id' => $organization?->college_id,
        ]);

        if ($roleName === 'Super Admin (OSA)') {
            $user->assignRole('Super Admin (OSA)');

            return $user;
        }

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $roleName,
            organization: $organization,
            college: $organization?->college,
            academicYear: $this->schoolYear->label,
            termStart: $this->schoolYear->starts_on?->toDateString(),
            termEnd: $this->schoolYear->ends_on?->toDateString(),
            positionTitle: 'Test admin',
            approvedBy: $user,
            isPrimaryAdmin: true,
        );

        return $user;
    }

    private function createStudent(Organization $organization, Program $program): User
    {
        $studentId = '2026-'.fake()->unique()->numberBetween(10000, 99999);
        $user = User::factory()->create([
            'name' => 'Participant Scanner',
            'email' => 'participant-scanner-'.uniqid().'@usm.edu.ph',
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'program_id' => $program->id,
            'student_id_number' => $studentId,
            'qr_code_value' => 'QR-'.$studentId,
            'program_code' => $program->code,
            'user_type' => 'student',
        ]);
        $user->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'college_id' => $organization->college_id,
            'program_id' => $program->id,
            'membership_type' => 'primary_academic',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'source' => 'test',
            'verified_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validParticipantPacketData(Organization $organization): array
    {
        return [
            'proponent_name' => 'Participant Lead',
            'proponent_position' => 'President',
            'proponent_organization' => $organization->name,
            'proponent_email' => 'participant.lead@usm.edu.ph',
            'delegate_count' => 12,
            'source_of_fund' => 'Organization funds',
            'budget_cost' => 0,
        ];
    }
}
