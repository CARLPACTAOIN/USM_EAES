<?php

namespace App\Services;

use App\Models\ApprovalTrail;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventPermitVersion;
use App\Models\PermitCopyOwner;
use App\Models\ProposalWorkflowTask;
use App\Models\ScannerSession;
use App\Models\User;
use App\Support\EventEvaluationForm;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventPermitService
{
    public function __construct(
        private readonly EventProposalSubmissionGuard $submissionGuard,
        private readonly ParticipationScopeService $participationScopes,
        private readonly ScannerSessionService $scannerSessions,
        private readonly AdminScopeService $adminScopes,
        private readonly EventNotificationService $notifications,
        private readonly ProposalWorkflowService $proposalWorkflows,
        private readonly LegacyProposalCompatibilityProjection $legacyProjection,
    ) {}

    public function submit(Event $event, User $actor, bool $returnedTasksAddressed = false): Event
    {
        $this->authorizeOrganizer($event, $actor);

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REVISION_REQUESTED], true)) {
            throw new DomainException('Only draft or revision-requested proposals can be submitted.');
        }

        $this->submissionGuard->assertSubmittable($event);
        $isRevisionResubmission = $event->status === Event::STATUS_REVISION_REQUESTED;
        $reopenedTasks = collect();

        $submittedEvent = DB::transaction(function () use ($event, $actor, $isRevisionResubmission, $returnedTasksAddressed, &$reopenedTasks): Event {
            if ($isRevisionResubmission) {
                $reopenedTasks = $this->proposalWorkflows->reopenReturnedTasksForResubmission($event, $returnedTasksAddressed);
            }

            $event->update(['status' => Event::STATUS_SUBMITTED]);
            $this->participationScopes->ensureOrganizerScope($event)->update([
                'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'review_remarks' => null,
            ]);
            $this->proposalWorkflows->bindFirstSubmission($event, $actor);
            $this->audit($event, $actor, $event->pending_venue_amendment_reason ? 'venue-amendment-submitted' : 'event-proposal-submitted');
            if ($event->pending_venue_amendment_reason) {
                $this->notifications->notifyPermitWorkflow($event, 'venue-amendment-submitted');
            } elseif ($reopenedTasks->isEmpty()) {
                $this->notifications->notifyProposalSubmitted($event);
            }

            return $event->fresh();
        });

        $this->notifyResubmittedWorkflowTasks($reopenedTasks);

        return $submittedEvent;
    }

    public function startReview(Event $event, User $actor): Event
    {
        $this->authorizeOsa($actor);

        if ($event->status !== Event::STATUS_SUBMITTED) {
            throw new DomainException('Only submitted proposals can be reviewed.');
        }

        $event->update(['status' => Event::STATUS_UNDER_REVIEW]);
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $event->fresh();
    }

    public function approve(Event $event, User $actor): Event
    {
        $this->authorizeOsa($actor);

        if (! in_array($event->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
            throw new DomainException('Proposal must be submitted or under review to approve.');
        }

        if (! EventEvaluationForm::hasLockedForm($event)) {
            throw new DomainException('A locked evaluation form with at least one question is required before approval.');
        }

        $this->submissionGuard->assertSubmittable($event);

        return DB::transaction(function () use ($event, $actor): Event {
            $lockedEvent = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedEvent->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
                throw new DomainException('Proposal was already processed by another reviewer.');
            }
            $lockedEvent->loadMissing(['canonicalPpaPacket', 'organization', 'venue']);
            $this->assertDigitalPermitGateSatisfied($lockedEvent);

            $approvedAt = now();
            $versionNumber = $lockedEvent->permit_version + 1;
            $approvalMetadata = $this->approvalMetadataSnapshot($lockedEvent);
            $verificationReference = $this->verificationReference($approvalMetadata, $lockedEvent, $versionNumber);

            $lockedEvent->permitVersions()->whereNull('superseded_at')->update(['superseded_at' => $approvedAt]);

            $permitVersion = EventPermitVersion::create([
                'event_id' => $lockedEvent->id,
                'workflow_run_id' => $lockedEvent->workflowRun?->id,
                'version_number' => $versionNumber,
                'venue_id' => $lockedEvent->venue_id,
                'venue_name_snapshot' => $lockedEvent->venue?->name ?: $lockedEvent->location_details,
                'venue_location_snapshot' => $lockedEvent->venue?->campus_location,
                'location_type' => $lockedEvent->location_type,
                'location_details' => $lockedEvent->location_details,
                'confirmation_office' => $lockedEvent->venue_confirmation_office,
                'confirmation_date' => $lockedEvent->venue_confirmation_date,
                'confirmation_reference_number' => $lockedEvent->venue_confirmation_reference_number,
                'confirmation_document_path' => $lockedEvent->venue_confirmation_document_path,
                'confirmation_document_original_name' => $lockedEvent->venue_confirmation_document_original_name,
                'ppa_document_path' => $lockedEvent->proposal_document_path,
                'ppa_document_original_name' => $lockedEvent->proposal_document_original_name,
                'amendment_reason' => $lockedEvent->pending_venue_amendment_reason,
                'approved_by' => $actor->id,
                'approved_at' => $approvedAt,
                'verification_reference' => $verificationReference,
                'canonical_packet_snapshot' => $lockedEvent->canonicalPpaPacket?->toArray(),
                'proposal_snapshot' => $this->proposalSnapshot($lockedEvent),
                'permit_snapshot' => $this->permitSnapshot($lockedEvent, $versionNumber, $actor, $approvedAt, $verificationReference),
                'evidence_snapshot' => $this->evidenceSnapshot($lockedEvent),
                'approval_metadata_snapshot' => $approvalMetadata,
            ]);
            $this->createPermitCopyOwners($permitVersion, $lockedEvent, $approvedAt);

            $isAmendment = filled($lockedEvent->pending_venue_amendment_reason);
            $lockedEvent->update([
                'status' => Event::STATUS_APPROVED,
                'lifecycle_phase' => 'scheduled',
                'permit_version' => $versionNumber,
                'pending_venue_amendment_reason' => null,
            ]);
            $this->participationScopes->ensureOrganizerScope($lockedEvent->fresh())->update([
                'ppa_status' => EventParticipationScope::PPA_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $approvedAt,
                'approved_at' => $approvedAt,
                'review_remarks' => null,
            ]);
            $this->audit($lockedEvent, $actor, $isAmendment ? 'venue-amendment-approved' : 'event-proposal-approved', [
                'permit_version' => $versionNumber,
            ]);
            $this->audit($lockedEvent, $actor, 'permit-version-created', ['permit_version' => $versionNumber]);
            $this->notifications->notifyPermitWorkflow($lockedEvent, $isAmendment ? 'venue-amendment-approved' : 'permit-version-created');
            $this->notifications->notifyFinalPermitApproved($lockedEvent, $versionNumber);

            return $lockedEvent->fresh(['venue', 'permitVersions']);
        });
    }

    public function requestRevision(Event $event, User $actor, string $remarks): Event
    {
        $this->authorizeOsa($actor);
        $this->assertReviewable($event);

        return DB::transaction(function () use ($event, $actor, $remarks): Event {
            $event->update(['status' => Event::STATUS_REVISION_REQUESTED]);
            $organizerScope = $this->participationScopes->ensureOrganizerScope($event);
            $organizerScope->update([
                'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_remarks' => trim($remarks),
            ]);
            $this->notifications->notifyProposalRevisionRequested($organizerScope->fresh(['event.organization', 'organization', 'delegatedOrganization']));
            $this->audit($event, $actor, $event->pending_venue_amendment_reason ? 'venue-amendment-revision-requested' : 'event-proposal-revision-requested');
            if ($event->pending_venue_amendment_reason) {
                $this->notifications->notifyPermitWorkflow($event, 'venue-amendment-revision-requested');
            }

            return $event->fresh();
        });
    }

    public function reject(Event $event, User $actor): Event
    {
        $this->authorizeOsa($actor);
        $this->assertReviewable($event);

        $event->update(['status' => Event::STATUS_REJECTED]);
        $this->participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $this->audit($event, $actor, $event->pending_venue_amendment_reason ? 'venue-amendment-rejected' : 'event-proposal-rejected');
        if ($event->pending_venue_amendment_reason) {
            $this->notifications->notifyPermitWorkflow($event, 'venue-amendment-rejected');
        } else {
            $this->notifications->notifyEventRejected($event);
        }

        return $event->fresh();
    }

    public function beginVenueAmendment(Event $event, User $actor, string $reason): Event
    {
        $this->authorizeOrganizer($event, $actor);

        if ($event->status !== Event::STATUS_APPROVED || $event->lifecycle_phase !== 'scheduled') {
            throw new DomainException('Venue amendments may only begin for approved, scheduled events.');
        }

        $event->loadMissing('eventDays');
        $firstEventDate = $event->eventDays->min('date') ?: $event->start_date;
        if (! $firstEventDate || now()->startOfDay()->greaterThanOrEqualTo($firstEventDate->copy()->startOfDay())) {
            throw new DomainException('Venue amendments must begin before the first event day.');
        }

        if (blank(trim($reason))) {
            throw new DomainException('An amendment reason is required.');
        }

        return DB::transaction(function () use ($event, $actor, $reason): Event {
            $event->permitVersions()->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $event->scannerSessions()->where('status', ScannerSession::STATUS_ACTIVE)->get()
                ->each(fn (ScannerSession $session) => $this->scannerSessions->revoke($session, $actor, 'Venue amendment started; approved permit superseded.'));

            $returnedTasks = $this->proposalWorkflows->returnVenueDependentTasksForAmendment($event, $actor, $reason);
            $this->legacyProjection->syncOrganizer($event);

            $event->update([
                'status' => Event::STATUS_REVISION_REQUESTED,
                'pending_venue_amendment_reason' => trim($reason),
                'venue_id' => null,
                'location_details' => null,
                'venue_confirmation_office' => null,
                'venue_confirmation_date' => null,
                'venue_confirmation_reference_number' => null,
                'venue_confirmation_document_path' => null,
                'venue_confirmation_document_original_name' => null,
                'proposal_document_path' => null,
                'proposal_document_original_name' => null,
            ]);
            $this->participationScopes->ensureOrganizerScope($event)->update([
                'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
                'ppa_softcopy_path' => null,
                'ppa_softcopy_original_name' => null,
                'review_remarks' => 'Venue amendment: '.trim($reason),
            ]);
            $this->audit($event, $actor, 'permit-version-superseded', ['permit_version' => $event->permit_version]);
            $this->audit($event, $actor, 'venue-amendment-initiated', [
                'reason' => trim($reason),
                'returned_workflow_task_ids' => $returnedTasks->pluck('id')->values()->all(),
            ]);
            $this->notifications->notifyPermitWorkflow($event, 'venue-amendment-initiated');

            return $event->fresh();
        });
    }

    private function assertReviewable(Event $event): void
    {
        if (! in_array($event->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
            throw new DomainException('Proposal must be submitted or under review.');
        }
    }

    private function assertDigitalPermitGateSatisfied(Event $event): void
    {
        $run = $event->workflowRun()
            ->lockForUpdate()
            ->first();

        if (! $run) {
            return;
        }

        $openGateTasks = ProposalWorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->whereHas('processStep', fn ($query) => $query->where('is_required', true)->where('is_gate', true))
            ->whereNotIn('status', [
                ProposalWorkflowTask::STATUS_APPROVED,
                ProposalWorkflowTask::STATUS_NOT_REQUIRED,
            ])
            ->lockForUpdate()
            ->get(['id']);

        if ($openGateTasks->isNotEmpty()) {
            throw new DomainException('Digital Permit is locked until all required permit-gating workflow tasks are approved or marked Not Required.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalMetadataSnapshot(Event $event): array
    {
        $run = $event->workflowRun()
            ->with(['tasks.approvalTrails.actor'])
            ->first();

        if (! $run) {
            return [];
        }

        return $run->tasks
            ->sortBy('display_order')
            ->flatMap(fn (ProposalWorkflowTask $task) => $task->approvalTrails
                ->sortBy('decided_at')
                ->map(fn (ApprovalTrail $trail): array => [
                    'approval_trail_id' => $trail->id,
                    'workflow_task_id' => $task->id,
                    'step_key' => $task->step_key,
                    'step_title' => $task->title,
                    'assigned_role' => $trail->assigned_role,
                    'assigned_scope' => $trail->assigned_scope,
                    'decision' => $trail->decision,
                    'remarks' => $trail->remarks,
                    'reference_id' => $trail->reference_id,
                    'actor_id' => $trail->actor_id,
                    'actor_name' => $trail->actor?->name,
                    'actor_email' => $trail->actor?->email,
                    'decided_at' => $trail->decided_at?->toIso8601String(),
                ]))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $approvalMetadata
     */
    private function verificationReference(array $approvalMetadata, Event $event, int $versionNumber): string
    {
        $osaApproval = collect($approvalMetadata)
            ->filter(fn (array $metadata): bool => ($metadata['assigned_role'] ?? null) === 'osa')
            ->last();

        return (string) ($osaApproval['reference_id'] ?? data_get(
            collect($approvalMetadata)->last(),
            'reference_id',
            'DP-'.strtoupper(substr(str_replace('-', '', $event->id), 0, 12)).'-V'.$versionNumber,
        ));
    }

    /** @return array<string, mixed> */
    private function proposalSnapshot(Event $event): array
    {
        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'proposal_category' => $event->proposal_category,
            'organization_id' => $event->organization_id,
            'organization_name' => $event->organization?->name,
            'organization_name_snapshot' => $event->organization_name_snapshot ?: data_get($event->governance_snapshots, 'organization_name_snapshot') ?: $event->organization?->name,
            'college_name_snapshot' => $event->college_name_snapshot ?: data_get($event->governance_snapshots, 'college_name_snapshot') ?: $event->organization?->college?->name,
            'department_name_snapshot' => $event->department_name_snapshot ?: data_get($event->governance_snapshots, 'department_name_snapshot') ?: $event->department?->name ?: $event->organization?->department?->name,
            'program_name_snapshot' => $event->program_name_snapshot ?: data_get($event->governance_snapshots, 'program_name_snapshot') ?: $event->program?->name,
            'proponent_name_snapshot' => $event->proponent_name_snapshot ?: data_get($event->governance_snapshots, 'proponent_name_snapshot') ?: $event->proponent?->name,
            'school_year_id' => $event->school_year_id,
            'school_year_label' => data_get($event->governance_snapshots, 'metadata.school_year_label') ?: $event->schoolYear?->label,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'location_type' => $event->location_type,
            'location_details' => $event->location_details,
            'governance_snapshots' => $event->governance_snapshots,
        ];
    }

    /** @return array<string, mixed> */
    private function permitSnapshot(Event $event, int $versionNumber, User $approver, \DateTimeInterface $approvedAt, string $verificationReference): array
    {
        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'version_number' => $versionNumber,
            'verification_reference' => $verificationReference,
            'approved_by' => [
                'id' => $approver->id,
                'name' => $approver->name,
                'email' => $approver->email,
            ],
            'approved_at' => $approvedAt->format(DATE_ATOM),
            'venue' => [
                'id' => $event->venue_id,
                'name' => $event->venue?->name ?: $event->location_details,
                'location' => $event->venue?->campus_location,
                'details' => $event->location_details,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceSnapshot(Event $event): array
    {
        return [
            'venue_confirmation_office' => $event->venue_confirmation_office,
            'venue_confirmation_date' => $event->venue_confirmation_date?->toDateString(),
            'venue_confirmation_reference_number' => $event->venue_confirmation_reference_number,
            'venue_confirmation_document_path' => $event->venue_confirmation_document_path,
            'venue_confirmation_document_original_name' => $event->venue_confirmation_document_original_name,
            'ppa_document_path' => $event->proposal_document_path,
            'ppa_document_original_name' => $event->proposal_document_original_name,
        ];
    }

    private function createPermitCopyOwners(EventPermitVersion $permitVersion, Event $event, \DateTimeInterface $assignedAt): void
    {
        $permitVersion->copyOwners()->createMany([
            [
                'event_id' => $event->id,
                'owner_type' => PermitCopyOwner::TYPE_OSA,
                'owner_name' => 'Office of Student Affairs',
                'owner_role' => 'Final permit approver and institutional custodian',
                'copy_label' => 'OSA official copy',
                'assigned_at' => $assignedAt,
            ],
            [
                'event_id' => $event->id,
                'owner_type' => PermitCopyOwner::TYPE_SECURITY,
                'owner_name' => 'Office of Security',
                'owner_role' => 'Security clearance and campus access custodian',
                'copy_label' => 'Office of Security official copy',
                'assigned_at' => $assignedAt,
            ],
            [
                'event_id' => $event->id,
                'owner_type' => PermitCopyOwner::TYPE_ORGANIZER,
                'owner_id' => $event->organization_id,
                'owner_name' => $event->organization_name_snapshot ?: $event->organization?->name ?: 'Organizing Society',
                'owner_role' => 'Organizing society or organization custodian',
                'copy_label' => 'Organizer official copy',
                'assigned_at' => $assignedAt,
            ],
        ]);
    }

    private function authorizeOrganizer(Event $event, User $actor): void
    {
        $scope = $this->adminScopes->forUser($actor);
        if (! $actor->can('create-proposals') || ! $scope || $scope->isSuperAdmin() || $scope->organizationId !== $event->organization_id) {
            throw new DomainException('Only the event organizer can perform this action.');
        }
    }

    private function authorizeOsa(User $actor): void
    {
        if (! $actor->hasRole('Super Admin (OSA)') || ! $actor->can('approve-proposals')) {
            throw new DomainException('Only OSA may review event proposals.');
        }
    }

    /**
     * @param  Collection<int, ProposalWorkflowTask>  $tasks
     */
    private function notifyResubmittedWorkflowTasks(Collection $tasks): void
    {
        $tasks->each(fn (ProposalWorkflowTask $task): int => $this->notifications->notifyWorkflowTaskResubmitted($task));
    }

    /** @param array<string, mixed> $details */
    private function audit(Event $event, User $actor, string $action, array $details = []): void
    {
        AuditLog::create([
            'target_id' => $event->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => ['event_id' => $event->id] + $details,
        ]);
    }
}
