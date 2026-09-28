<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\User;
use App\Notifications\EventWorkflowNotification;
use App\Services\AdminAssignmentService;
use App\Support\ProposalRevisionTargets;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProposalOrganizerChecklistTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $schoolYear;

    private College $college;

    private Organization $organization;

    private User $organizer;

    private User $approver;

    private ProposalProcessTemplateVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addMonths(10),
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $this->college = College::factory()->create([
            'name' => 'College of Checklist Workflows',
            'code' => 'CCW',
        ]);

        $this->organization = Organization::create([
            'college_id' => $this->college->id,
            'name' => 'Checklist Society',
            'acronym' => 'CLS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $this->organizer = User::factory()->create([
            'college_id' => $this->college->id,
            'email' => 'organizer.checklist@usm.edu.ph',
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $this->organizer,
            roleName: 'Society Admin',
            organization: $this->organization,
            college: $this->college,
            academicYear: $this->schoolYear->label,
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'President',
            approvedBy: $this->organizer,
            isPrimaryAdmin: true,
        );

        $this->approver = User::factory()->create([
            'email' => 'osa.checklist@usm.edu.ph',
        ]);
        $this->approver->assignRole('Super Admin (OSA)');

        AdminAssignment::create([
            'user_id' => $this->approver->id,
            'role_name' => 'Super Admin (OSA)',
            'position_title' => 'OSA Workflow Approver',
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        $template = ProposalProcessTemplate::create([
            'name' => 'Organizer Checklist Test Template',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $this->version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);
    }

    public function test_organizer_checklist_renders_full_workflow_rail_and_current_revision_action(): void
    {
        $event = $this->eventWithPacket(Event::STATUS_REVISION_REQUESTED);
        $run = $this->workflowRun($event, ProposalWorkflowRun::STATUS_REVISION_REQUESTED);

        $this->task($run, 'intake', 'Packet Intake', 1, ProposalWorkflowTask::STATUS_APPROVED);
        $this->task(
            $run,
            'adviser_review',
            'Adviser Review',
            2,
            ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            'Clarify the budget source.',
            [
                'revision' => [
                    'target' => [
                        'type' => 'packet_section',
                        'key' => 'budget',
                        'label' => 'Budget and funding',
                        'description' => 'Source of fund, budget cost, and budget breakdown.',
                        'anchor' => 'packet-section-budget',
                    ],
                ],
            ],
        );
        $this->task($run, 'chair_review', 'Department Chair Review', 3, ProposalWorkflowTask::STATUS_AVAILABLE);
        $this->task($run, 'security_review', 'Security Clearance', 4, ProposalWorkflowTask::STATUS_IN_PROGRESS);
        $this->task($run, 'osa_approval', 'OSA Approval', 5, ProposalWorkflowTask::STATUS_BLOCKED);

        $this->actingAs($this->organizer)
            ->get(route('dashboard.events.checklist', $event))
            ->assertOk()
            ->assertSee('Organizer Checklist')
            ->assertSeeTextInOrder([
                '1',
                'Packet Intake',
                'Approved',
                '2',
                'Adviser Review',
                'Revision Requested',
                '3',
                'Department Chair Review',
                'Available',
                '4',
                'Security Clearance',
                'Pending',
                '5',
                'OSA Approval',
                'Blocked',
            ])
            ->assertSee('Clarify the budget source.')
            ->assertSee('Review target: Budget and funding')
            ->assertSee('#packet-section-budget', false)
            ->assertSee('Edit returned packet')
            ->assertSee('modal=proposal-edit', false)
            ->assertSee('Resubmit proposal');
    }

    public function test_revision_resubmission_reuses_workflow_run_reopens_returned_tasks_and_notifies_approver(): void
    {
        Notification::fake();

        $event = $this->eventWithPacket(Event::STATUS_REVISION_REQUESTED);
        $run = $this->workflowRun($event, ProposalWorkflowRun::STATUS_REVISION_REQUESTED);
        $returnedTask = $this->task($run, 'adviser_review', 'Adviser Review', 1, ProposalWorkflowTask::STATUS_REVISION_REQUESTED, 'Correct the participant estimate.');
        $returnedTask->forceFill([
            'metadata' => array_replace_recursive($returnedTask->metadata ?? [], [
                'revision' => [
                    'target' => [
                        'type' => 'packet_section',
                        'key' => 'beneficiaries',
                        'label' => 'Expected output and beneficiaries',
                        'description' => 'Expected output, participant groups, and beneficiary details.',
                        'anchor' => 'packet-section-beneficiaries',
                    ],
                ],
            ]),
        ])->save();
        $returnedTask->forceFill([
            'metadata' => array_replace_recursive($returnedTask->metadata ?? [], [
                'revision' => [
                    'target_fingerprint' => app(ProposalRevisionTargets::class)->targetFingerprintForTask(
                        $returnedTask,
                        data_get($returnedTask->metadata, 'revision.target')
                    ),
                ],
            ]),
        ])->save();

        $returnedTask->forceFill([
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'decided_by' => $this->approver->id,
            'decided_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ])->save();

        ApprovalTrail::create([
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $returnedTask->id,
            'actor_id' => $this->approver->id,
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'remarks' => 'Correct the participant estimate.',
            'reference_id' => 'AT-checklist-resubmit',
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            'assigned_role' => 'osa',
            'assigned_scope' => 'university',
            'decided_at' => now()->subHour(),
        ]);

        $event->canonicalPpaPacket->forceFill(['beneficiaries' => 'Updated student participant estimate.'])->save();

        $this->actingAs($this->organizer)
            ->post(route('dashboard.events.submit', $event), [
                'returned_tasks_addressed' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(Event::STATUS_SUBMITTED, $event->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_IN_PROGRESS, $run->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $returnedTask->fresh()->status);
        $this->assertSame($run->id, $event->fresh()->workflowRun->id);
        $this->assertSame(1, ProposalWorkflowRun::where('event_id', $event->id)->count());
        $this->assertNotNull(data_get($returnedTask->fresh()->metadata, 'revision.addressed_at'));

        Notification::assertSentTo(
            $this->approver,
            EventWorkflowNotification::class,
            fn (EventWorkflowNotification $notification): bool => ($notification->toDatabase($this->approver)['kind'] ?? null) === 'workflow_task_resubmitted'
                && ($notification->toDatabase($this->approver)['workflow_task_id'] ?? null) === $returnedTask->id
        );
    }

    public function test_revision_resubmission_requires_packet_correction_after_latest_returned_task(): void
    {
        $event = $this->eventWithPacket(Event::STATUS_REVISION_REQUESTED);
        $run = $this->workflowRun($event, ProposalWorkflowRun::STATUS_REVISION_REQUESTED);
        $returnedTask = $this->task($run, 'adviser_review', 'Adviser Review', 1, ProposalWorkflowTask::STATUS_REVISION_REQUESTED, 'Update the objectives.');

        $returnedAt = now()->subHour();
        $returnedTask->forceFill([
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'decided_by' => $this->approver->id,
            'decided_at' => $returnedAt,
            'completed_at' => $returnedAt,
        ])->save();
        CanonicalPpaPacket::withoutTimestamps(fn () => $event->canonicalPpaPacket->forceFill([
            'updated_at' => $returnedAt->copy()->subMinute(),
        ])->save());
        Event::withoutTimestamps(fn () => $event->forceFill([
            'updated_at' => $returnedAt->copy()->subMinute(),
        ])->save());

        $event->canonicalPpaPacket->forceFill(['beneficiaries' => 'Updated after revision.'])->save();

        $this->actingAs($this->organizer)
            ->from(route('dashboard.events.checklist', $event))
            ->post(route('dashboard.events.submit', $event))
            ->assertRedirect(route('dashboard.events.checklist', $event))
            ->assertSessionHas('error', 'Confirm that returned workflow tasks and packet corrections were addressed before resubmitting.');

        $this->assertSame(Event::STATUS_REVISION_REQUESTED, $event->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_REVISION_REQUESTED, $returnedTask->fresh()->status);

        CanonicalPpaPacket::withoutTimestamps(fn () => $event->canonicalPpaPacket->forceFill([
            'updated_at' => $returnedAt->copy()->subMinute(),
        ])->save());

        $this->actingAs($this->organizer)
            ->from(route('dashboard.events.checklist', $event))
            ->post(route('dashboard.events.submit', $event), [
                'returned_tasks_addressed' => '1',
            ])
            ->assertRedirect(route('dashboard.events.checklist', $event))
            ->assertSessionHas('error', 'Edit the returned proposal packet before resubmitting the workflow task.');
    }

    public function test_attachment_targeted_revision_requires_specific_attachment_update_before_resubmission(): void
    {
        $event = $this->eventWithPacket(Event::STATUS_REVISION_REQUESTED);
        $run = $this->workflowRun($event, ProposalWorkflowRun::STATUS_REVISION_REQUESTED);
        $returnedAt = now()->subHour();
        $returnedTask = $this->task(
            $run,
            'osa_review',
            'OSA Review',
            1,
            ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            'Replace the supporting evidence.',
            [
                'revision' => [
                    'target' => [
                        'type' => 'attachment_requirement',
                        'key' => CanonicalPpaAttachment::TYPE_SUPPORTING,
                        'label' => 'Supporting checklist evidence',
                        'description' => 'Participant or checklist support document required by the packet.',
                        'anchor' => 'attachment-requirement-supporting',
                    ],
                ],
            ],
        );

        $returnedTask->forceFill([
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'decided_by' => $this->approver->id,
            'decided_at' => $returnedAt,
            'completed_at' => $returnedAt,
        ])->save();

        $event->canonicalPpaPacket->forceFill(['beneficiaries' => 'Updated packet text without replacing evidence.'])->save();

        $this->actingAs($this->organizer)
            ->from(route('dashboard.events.checklist', $event))
            ->post(route('dashboard.events.submit', $event), [
                'returned_tasks_addressed' => '1',
            ])
            ->assertRedirect(route('dashboard.events.checklist', $event))
            ->assertSessionHas('error', 'Update the targeted revision item (Supporting checklist evidence) before resubmitting.');

        $this->assertSame(Event::STATUS_REVISION_REQUESTED, $event->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_REVISION_REQUESTED, $returnedTask->fresh()->status);

        CanonicalPpaAttachment::factory()
            ->for($event->canonicalPpaPacket, 'packet')
            ->create([
                'attachment_type' => CanonicalPpaAttachment::TYPE_SUPPORTING,
                'path' => 'canonical-ppa/replaced-supporting.pdf',
                'original_name' => 'replaced-supporting.pdf',
                'uploaded_by_user_id' => $this->organizer->id,
            ]);

        $event->canonicalPpaPacket->forceFill(['expected_output' => 'Updated after replacing evidence.'])->save();

        $this->actingAs($this->organizer)
            ->post(route('dashboard.events.submit', $event), [
                'returned_tasks_addressed' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(Event::STATUS_SUBMITTED, $event->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $returnedTask->fresh()->status);
    }

    private function eventWithPacket(string $status): Event
    {
        $event = Event::factory()->create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => 'Checklist Revision Forum',
            'status' => $status,
            'hardcopy_submitted' => true,
            'head_organization_signed' => true,
            'adviser_signed' => true,
            'college_name_snapshot' => $this->college->name,
            'organization_name_snapshot' => $this->organization->name,
            'proponent_name_snapshot' => $this->organizer->name,
            'proponent_user_id' => $this->organizer->id,
        ]);

        EventParticipationScope::create([
            'event_id' => $event->id,
            'organization_id' => $this->organization->id,
            'college_id' => $this->college->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => $status === Event::STATUS_REVISION_REQUESTED
                ? EventParticipationScope::PPA_REVISION_REQUESTED
                : EventParticipationScope::PPA_SUBMITTED,
            'requires_fee' => false,
            'fee_amount' => null,
            'review_remarks' => $status === Event::STATUS_REVISION_REQUESTED ? 'Returned by workflow approver.' : null,
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'title' => $event->title,
            'proponent_name' => $this->organizer->name,
            'proponent_organization' => $this->organization->name,
            'proponent_email' => $this->organizer->email,
            'proponent_contact_number' => '09170000000',
            'rationale' => 'A checklist workflow forum for student leaders.',
            'general_objective' => 'Improve student workflow literacy.',
            'specific_objectives' => ['Explain the approval workflow.'],
            'methodology' => 'Forum and guided workshop.',
            'program_activities' => [[
                'time' => '08:00 - 10:00',
                'activity' => 'Workflow forum',
                'person_in_charge' => 'Organizer Lead',
            ]],
            'expected_output' => 'Corrected proposal packet.',
            'beneficiaries' => 'Student leaders',
            'budget_breakdown' => [['item' => 'Materials', 'amount' => '1000']],
        ]);

        return $event->fresh(['canonicalPpaPacket']);
    }

    private function workflowRun(Event $event, string $status): ProposalWorkflowRun
    {
        return ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $this->version->id,
            'status' => $status,
            'started_by' => $this->organizer->id,
            'started_at' => now()->subDay(),
            'submitted_at' => now()->subDay(),
        ]);
    }

    private function task(
        ProposalWorkflowRun $run,
        string $stepKey,
        string $title,
        int $order,
        string $status,
        ?string $remarks = null,
        ?array $metadata = null
    ): ProposalWorkflowTask {
        $step = ProposalProcessStep::create([
            'template_version_id' => $this->version->id,
            'step_key' => $stepKey,
            'title' => $title,
            'description' => $title.' description.',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => $order,
            'is_required' => true,
            'condition_config' => [
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
            ],
        ]);

        $task = ProposalWorkflowTask::create([
            'workflow_run_id' => $run->id,
            'process_step_id' => $step->id,
            'status' => $status,
            'display_order' => $order,
            'step_key' => $stepKey,
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'title' => $title,
            'description' => $title.' description.',
            'assigned_user_id' => $this->approver->id,
            'assigned_role' => 'osa',
            'assigned_scope' => 'university',
            'available_at' => in_array($status, [ProposalWorkflowTask::STATUS_AVAILABLE, ProposalWorkflowTask::STATUS_IN_PROGRESS], true) ? now() : null,
            'remarks' => $remarks,
            'metadata' => array_replace_recursive([
                'assignment' => [
                    'role_key' => 'osa',
                    'scope_key' => 'university',
                    'scope' => [
                        'school_year_id' => $this->schoolYear->id,
                        'organization_id' => $this->organization->id,
                        'organization_name' => $this->organization->name,
                    ],
                    'assigned_user_snapshot' => [
                        'id' => $this->approver->id,
                        'name' => $this->approver->name,
                        'email' => $this->approver->email,
                    ],
                ],
            ], $metadata ?? []),
        ]);

        $target = data_get($task->metadata, 'revision.target');
        if (is_array($target)) {
            $taskMetadata = $task->metadata ?? [];
            data_set($taskMetadata, 'revision.target_fingerprint', app(ProposalRevisionTargets::class)->targetFingerprintForTask($task, $target));
            $task->forceFill(['metadata' => $taskMetadata])->save();
        }

        return $task;
    }
}
