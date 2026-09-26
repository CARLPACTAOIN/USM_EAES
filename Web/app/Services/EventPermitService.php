<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventPermitVersion;
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
            $versionNumber = $lockedEvent->permit_version + 1;
            $lockedEvent->permitVersions()->whereNull('superseded_at')->update(['superseded_at' => now()]);

            EventPermitVersion::create([
                'event_id' => $lockedEvent->id,
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
                'approved_at' => now(),
            ]);

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
                'reviewed_at' => now(),
                'approved_at' => now(),
                'review_remarks' => null,
            ]);
            $this->audit($lockedEvent, $actor, $isAmendment ? 'venue-amendment-approved' : 'event-proposal-approved', [
                'permit_version' => $versionNumber,
            ]);
            $this->audit($lockedEvent, $actor, 'permit-version-created', ['permit_version' => $versionNumber]);
            $this->notifications->notifyPermitWorkflow($lockedEvent, $isAmendment ? 'venue-amendment-approved' : 'permit-version-created');

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
            $this->audit($event, $actor, 'venue-amendment-initiated', ['reason' => trim($reason)]);
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
