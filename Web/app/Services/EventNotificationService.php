<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Notifications\EventWorkflowNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventNotificationService
{
    /** @var array<string, bool> */
    private array $dispatchedKeys = [];

    public function __construct(private readonly AdminScopeService $adminScopes) {}

    public function notifyParticipantScopeInvitation(EventParticipationScope $scope): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization', 'college']);

        if ($scope->participation_role !== EventParticipationScope::ROLE_PARTICIPANT || $scope->event?->status === 'draft') {
            return 0;
        }

        return $this->notifyUsers(
            $this->participantRecipientAdmins($scope),
            'participant_scope_invitation:'.$scope->id,
            'participant_scope_invitation',
            $scope,
            [
                'title' => 'Participant invitation needs acknowledgement',
                'body' => "{$scope->event->title} invited {$scope->displayName()} to submit a participant PPA.",
                'action_label' => 'Open Participating Events',
                'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                'severity' => 'warning',
                'needs_action' => true,
            ]
        );
    }

    public function notifyPendingInvitationsForEvent(Event $event): int
    {
        $event->loadMissing('participationScopes.organization', 'participationScopes.delegatedOrganization', 'organization');

        if ($event->status === 'draft') {
            return 0;
        }

        return $event->participationScopes
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->sum(fn (EventParticipationScope $scope): int => $this->notifyParticipantScopeInvitation($scope));
    }

    public function acknowledgeInvitation(EventParticipationScope $scope, User $actor): bool
    {
        if (! $this->canAcknowledgeInvitation($actor, $scope)) {
            return false;
        }

        if ($scope->invitation_acknowledged_at) {
            return true;
        }

        $scope->update([
            'invitation_acknowledged_at' => now(),
            'invitation_acknowledged_by' => $actor->id,
        ]);

        $this->notifyUsers(
            $this->organizerAndOsaRecipients($scope),
            'participant_scope_acknowledged:'.$scope->id,
            'participant_scope_acknowledged',
            $scope->fresh(['event.organization', 'organization', 'delegatedOrganization']),
            [
                'title' => 'Invitation acknowledged',
                'body' => "{$scope->displayName()} acknowledged the invitation for {$scope->event->title}.",
                'action_label' => 'View scopes',
                'action_url' => route('dashboard.events'),
                'severity' => 'success',
                'needs_action' => false,
            ]
        );

        return true;
    }

    public function notifyParticipantScopeSubmitted(EventParticipationScope $scope): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);

        return $this->notifyUsers(
            $this->organizerAndOsaRecipients($scope),
            'participant_scope_submitted:'.$scope->id.':'.optional($scope->submitted_at)->timestamp,
            'participant_scope_submitted',
            $scope,
            [
                'title' => 'Participant PPA submitted',
                'body' => "{$scope->displayName()} submitted a participant PPA for {$scope->event->title}.",
                'action_label' => 'Review scopes',
                'action_url' => route('dashboard.events'),
                'severity' => 'info',
                'needs_action' => true,
            ]
        );
    }

    public function notifyProposalRevisionRequested(EventParticipationScope $scope): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);

        return $this->notifyUsers(
            $this->organizerRecipients($scope),
            'event_proposal_revision_requested:'.$scope->event_id.':'.optional($scope->reviewed_at)->timestamp,
            'event_proposal_revision_requested',
            $scope,
            [
                'title' => 'Proposal revision requested',
                'body' => "OSA returned {$scope->event->title} for revision.",
                'action_label' => 'Revise proposal',
                'action_url' => route('dashboard.events', ['status' => Event::STATUS_REVISION_REQUESTED]),
                'severity' => 'warning',
                'needs_action' => true,
            ]
        );
    }

    public function notifyPermitWorkflow(Event $event, string $kind): int
    {
        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $recipients = in_array($kind, ['venue-amendment-submitted', 'venue-amendment-initiated'], true)
            ? $organizers->merge($this->osaRecipients())
            : $organizers;
        $label = str($kind)->replace('-', ' ')->title()->toString();

        return $this->notifyUsers(
            $recipients->unique('id')->values(),
            $kind.':'.$event->id.':'.$event->updated_at?->timestamp,
            $kind,
            $scope,
            [
                'title' => $label,
                'body' => $label.' for '.$event->title.'.',
                'action_label' => 'Open Event Proposals',
                'action_url' => route('dashboard.events'),
                'severity' => str_contains($kind, 'approved') ? 'success' : (str_contains($kind, 'rejected') ? 'warning' : 'info'),
                'needs_action' => str_contains($kind, 'submitted') || str_contains($kind, 'revision-requested'),
            ]
        );
    }

    public function notifyWorkflowTaskAssigned(ProposalWorkflowTask $task): int
    {
        $task->loadMissing(['workflowRun.event.organization', 'assignedUser']);

        if (! $task->assignedUser) {
            return 0;
        }

        $event = $task->workflowRun?->event;
        $key = 'workflow_task_assigned:'.$task->id.':'.$task->assigned_user_id;
        $isAvailable = $task->status === ProposalWorkflowTask::STATUS_AVAILABLE;

        $sent = $this->notifyUser($task->assignedUser, $key, [
            'kind' => 'workflow_task_assigned',
            'event_id' => $event?->id,
            'event_title' => $event?->title,
            'workflow_task_id' => $task->id,
            'workflow_run_id' => $task->workflow_run_id,
            'step_title' => $task->title,
            'assigned_role' => $task->assigned_role,
            'title' => 'Workflow task assigned',
            'body' => "You were assigned to {$task->title} for {$event?->title}.",
            'action_label' => 'Open Workflow Task',
            'action_url' => route('dashboard.workflow-tasks.show', $task),
            'severity' => 'info',
            'needs_action' => $isAvailable,
        ]);

        return $sent ? 1 : 0;
    }

    public function notifyProposalSubmitted(Event $event): int
    {
        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $submittedAt = optional($event->workflowRun?->submitted_at ?? $event->updated_at)->timestamp;
        $key = 'event_proposal_submitted:'.$event->id.':'.$submittedAt;

        return $this->notifyUsers(
            $organizers,
            $key,
            'event_proposal_submitted',
            $scope,
            [
                'title' => 'Event proposal submitted',
                'body' => "{$event->title} was submitted for institutional workflow review.",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'info',
                'needs_action' => false,
            ]
        );
    }

    public function notifyWorkflowAdvanced(ProposalWorkflowTask $task): int
    {
        $task->loadMissing('workflowRun.event.organization');
        $event = $task->workflowRun?->event;
        if (! $event) {
            return 0;
        }

        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $decidedAt = optional($task->decided_at ?? now())->timestamp;
        $key = 'workflow_advanced:'.$task->id.':'.$decidedAt;

        return $this->notifyUsers(
            $organizers,
            $key,
            'workflow_advanced',
            $scope,
            [
                'title' => 'Proposal workflow advanced',
                'body' => "{$task->title} was completed. Workflow advanced for {$event->title}.",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'info',
                'needs_action' => false,
                'workflow_task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
            ]
        );
    }

    public function notifyWorkflowTaskApproved(ProposalWorkflowTask $task): int
    {
        $task->loadMissing('workflowRun.event.organization');
        $event = $task->workflowRun?->event;
        if (! $event) {
            return 0;
        }

        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $decidedAt = optional($task->decided_at ?? now())->timestamp;
        $key = 'workflow_task_approved:'.$task->id.':'.$decidedAt;
        $remarks = filled($task->remarks) ? " Remarks: {$task->remarks}" : '';

        return $this->notifyUsers(
            $organizers,
            $key,
            'workflow_task_approved',
            $scope,
            [
                'title' => 'Workflow task approved',
                'body' => "{$task->title} was approved for {$event->title}.{$remarks}",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'success',
                'needs_action' => false,
                'workflow_task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'remarks' => $task->remarks,
            ]
        );
    }

    public function notifyWorkflowTaskRejected(ProposalWorkflowTask $task): int
    {
        $task->loadMissing('workflowRun.event.organization');
        $event = $task->workflowRun?->event;
        if (! $event) {
            return 0;
        }

        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $decidedAt = optional($task->decided_at ?? now())->timestamp;
        $key = 'workflow_task_rejected:'.$task->id.':'.$decidedAt;
        $remarks = filled($task->remarks) ? " Remarks: {$task->remarks}" : '';

        return $this->notifyUsers(
            $organizers,
            $key,
            'workflow_task_rejected',
            $scope,
            [
                'title' => 'Workflow task rejected',
                'body' => "{$task->title} was rejected for {$event->title}.{$remarks}",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'error',
                'needs_action' => false,
                'workflow_task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'remarks' => $task->remarks,
            ]
        );
    }

    public function notifyWorkflowTaskReassigned(ProposalWorkflowTask $task, User $replacement, ?User $formerAssignee, string $reason, string $historyId): int
    {
        $task->loadMissing('workflowRun.event');
        $event = $task->workflowRun?->event;
        $isAvailable = $task->status === ProposalWorkflowTask::STATUS_AVAILABLE;
        $sent = 0;

        $replacementKey = 'workflow_task_reassigned:'.$task->id.':'.$replacement->id.':'.$historyId;
        if ($this->notifyUser($replacement, $replacementKey, [
            'kind' => 'workflow_task_reassigned',
            'event_id' => $event?->id,
            'event_title' => $event?->title,
            'workflow_task_id' => $task->id,
            'workflow_run_id' => $task->workflow_run_id,
            'step_title' => $task->title,
            'title' => 'Workflow task reassigned to you',
            'body' => "{$task->title} for {$event?->title} was reassigned to you. Reason: {$reason}",
            'action_label' => 'Open Workflow Task',
            'action_url' => route('dashboard.workflow-tasks.show', $task),
            'severity' => 'info',
            'needs_action' => $isAvailable,
        ])) {
            $sent++;
        }

        if ($formerAssignee && $formerAssignee->id !== $replacement->id) {
            $formerKey = 'workflow_task_unassigned:'.$task->id.':'.$formerAssignee->id.':'.$historyId;
            if ($this->notifyUser($formerAssignee, $formerKey, [
                'kind' => 'workflow_task_unassigned',
                'event_id' => $event?->id,
                'event_title' => $event?->title,
                'workflow_task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'step_title' => $task->title,
                'title' => 'Workflow task reassigned',
                'body' => "{$task->title} for {$event?->title} was reassigned to another reviewer. Reason: {$reason}",
                'action_label' => 'View Workflow Tasks',
                'action_url' => route('dashboard.workflow-tasks.index'),
                'severity' => 'info',
                'needs_action' => false,
            ])) {
                $sent++;
            }
        }

        return $sent;
    }

    public function notifyWorkflowTaskUnblocked(ProposalWorkflowTask $task): int
    {
        $task->loadMissing(['workflowRun.event.organization', 'assignedUser']);

        if (! $task->assignedUser) {
            return 0;
        }

        $event = $task->workflowRun?->event;
        $key = 'workflow_task_unblocked:'.$task->id.':'.optional($task->available_at ?? now())->timestamp;

        $sent = $this->notifyUser($task->assignedUser, $key, [
            'kind' => 'workflow_task_unblocked',
            'event_id' => $event?->id,
            'event_title' => $event?->title,
            'workflow_task_id' => $task->id,
            'workflow_run_id' => $task->workflow_run_id,
            'step_title' => $task->title,
            'assigned_role' => $task->assigned_role,
            'title' => 'Workflow task ready for review',
            'body' => "{$task->title} for {$event?->title} is now unblocked and ready for your review.",
            'action_label' => 'Open Workflow Task',
            'action_url' => route('dashboard.workflow-tasks.show', $task),
            'severity' => 'info',
            'needs_action' => true,
        ]);

        return $sent ? 1 : 0;
    }

    public function notifyFinalPermitApproved(Event $event, int $versionNumber): int
    {
        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $key = 'final_permit_approved:'.$event->id.':'.$versionNumber;

        return $this->notifyUsers(
            $organizers,
            $key,
            'final_permit_approved',
            $scope,
            [
                'title' => 'Digital Permit Approved',
                'body' => "Digital permit version {$versionNumber} approved for {$event->title}.",
                'action_label' => 'View Digital Permit',
                'action_url' => route('dashboard.events.permit-preview', $event),
                'severity' => 'success',
                'needs_action' => false,
                'permit_version' => $versionNumber,
            ]
        );
    }

    public function notifyEventRejected(Event $event): int
    {
        $scope = $this->participationScopesForNotification($event);
        $organizers = $this->organizerRecipients($scope);
        $key = 'event_proposal_rejected:'.$event->id.':'.optional($event->updated_at)->timestamp;

        return $this->notifyUsers(
            $organizers,
            $key,
            'event_proposal_rejected',
            $scope,
            [
                'title' => 'Proposal rejected',
                'body' => "{$event->title} was rejected.",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'error',
                'needs_action' => false,
            ]
        );
    }

    public function notifyWorkflowTaskRevisionRequested(ProposalWorkflowTask $task): int
    {
        $task->loadMissing(['workflowRun.event.organization', 'assignedUser']);
        $event = $task->workflowRun->event;
        $scope = $this->participationScopesForNotification($event);
        $remarks = filled($task->remarks) ? " Remarks: {$task->remarks}" : '';

        return $this->notifyUsers(
            $this->organizerRecipients($scope),
            'workflow_task_revision_requested:'.$task->id.':'.optional($task->decided_at)->timestamp,
            'workflow_task_revision_requested',
            $scope,
            [
                'title' => 'Workflow task returned for revision',
                'body' => "{$task->title} returned {$event->title} for organizer correction.{$remarks}",
                'action_label' => 'Open Organizer Checklist',
                'action_url' => route('dashboard.events.checklist', $event),
                'severity' => 'warning',
                'needs_action' => true,
                'workflow_task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'remarks' => $task->remarks,
            ]
        );
    }

    public function notifyWorkflowTaskResubmitted(ProposalWorkflowTask $task): int
    {
        $task->loadMissing(['workflowRun.event.organization', 'assignedUser']);

        if (! $task->assignedUser) {
            return 0;
        }

        $event = $task->workflowRun->event;
        $key = 'workflow_task_resubmitted:'.$task->id.':'.optional($task->available_at)->timestamp;

        $sent = $this->notifyUser($task->assignedUser, $key, [
            'kind' => 'workflow_task_resubmitted',
            'event_id' => $event->id,
            'event_title' => $event->title,
            'workflow_task_id' => $task->id,
            'workflow_run_id' => $task->workflow_run_id,
            'step_title' => $task->title,
            'title' => 'Returned workflow task resubmitted',
            'body' => "{$event->title} was corrected and resubmitted for {$task->title}.",
            'action_label' => 'Open Workflow Task',
            'action_url' => route('dashboard.workflow-tasks.show', $task),
            'severity' => 'info',
            'needs_action' => true,
        ]);

        return $sent ? 1 : 0;
    }

    public function notifyParticipantScopeReviewed(EventParticipationScope $scope, string $status): int
    {
        $scope->loadMissing(['event.organization', 'organization', 'delegatedOrganization']);
        $label = ucwords(str_replace('_', ' ', $status));
        $needsAction = $status === EventParticipationScope::PPA_REVISION_REQUESTED;

        return $this->notifyUsers(
            $this->participantRecipientAdmins($scope)->merge($this->organizerRecipients($scope))->unique('id'),
            "participant_scope_{$status}:{$scope->id}:".optional($scope->reviewed_at)->timestamp,
            "participant_scope_{$status}",
            $scope,
            [
                'title' => "Participant PPA {$label}",
                'body' => "{$scope->displayName()} is now {$label} for {$scope->event->title}.",
                'action_label' => $needsAction ? 'Revise PPA' : 'View event',
                'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                'severity' => $status === EventParticipationScope::PPA_APPROVED ? 'success' : ($needsAction ? 'warning' : 'info'),
                'needs_action' => $needsAction,
            ]
        );
    }

    public function sendParticipantScopeReminders(): int
    {
        $today = now()->toDateString();
        $sent = 0;

        EventParticipationScope::query()
            ->with(['event.organization', 'organization', 'delegatedOrganization', 'college'])
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
            ->where(function ($query): void {
                $query->whereNull('invitation_acknowledged_at')
                    ->orWhere(function ($query): void {
                        $query->where('ppa_required', true)
                            ->whereIn('ppa_status', [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED]);
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('invitation_last_reminded_at')
                    ->orWhereDate('invitation_last_reminded_at', '<', now()->toDateString());
            })
            ->chunkById(100, function ($scopes) use ($today, &$sent): void {
                foreach ($scopes as $scope) {
                    $sent += $this->notifyUsers(
                        $this->participantRecipientAdmins($scope),
                        "participant_scope_reminder:{$scope->id}:{$today}",
                        'participant_scope_reminder',
                        $scope,
                        [
                            'title' => 'Participant invitation reminder',
                            'body' => "{$scope->event->title} still needs acknowledgement or participant PPA action.",
                            'action_label' => 'Open Participating Events',
                            'action_url' => route('dashboard.events', ['workspace' => 'participating']),
                            'severity' => 'warning',
                            'needs_action' => true,
                        ]
                    );

                    $scope->forceFill(['invitation_last_reminded_at' => now()])->save();
                }
            });

        return $sent;
    }

    public function canAcknowledgeInvitation(User $user, EventParticipationScope $scope): bool
    {
        $scope->loadMissing(['event', 'organization', 'delegatedOrganization']);
        $adminScope = $this->adminScopes->forUser($user);

        return $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT
            && $scope->status === EventParticipationScope::STATUS_ACTIVE
            && $scope->event?->status !== 'draft'
            && $adminScope
            && ! $adminScope->isSuperAdmin()
            && $this->adminScopeOwnsParticipantScope($adminScope, $scope);
    }

    public function summaryForUser(User $user): array
    {
        return [
            'unread_count' => $user->unreadNotifications()->count(),
            'needs_action_count' => $this->needsActionItemsForUser($user)->count(),
        ];
    }

    public function needsActionItemsForUser(User $user, int $limit = 6): Collection
    {
        $adminScope = $this->adminScopes->forUser($user);

        if (! $adminScope) {
            return collect();
        }

        if ($adminScope->isSuperAdmin()) {
            $participantItems = EventParticipationScope::query()
                ->with(['event.organization', 'organization'])
                ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
                ->where('status', EventParticipationScope::STATUS_ACTIVE)
                ->where('ppa_status', EventParticipationScope::PPA_SUBMITTED)
                ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
                ->latest('submitted_at')
                ->limit($limit)
                ->get()
                ->map(fn (EventParticipationScope $scope): array => $this->scopeActionItem($scope, 'Participant PPA submitted', route('dashboard.events')));

            return $participantItems->values();
        }

        return EventParticipationScope::query()
            ->with(['event.organization', 'organization', 'delegatedOrganization'])
            ->where('status', EventParticipationScope::STATUS_ACTIVE)
            ->whereHas('event', fn ($query) => $query->where('status', '!=', 'draft'))
            ->get()
            ->filter(function (EventParticipationScope $scope) use ($adminScope): bool {
                if ($scope->participation_role === EventParticipationScope::ROLE_ORGANIZER) {
                    return $scope->event?->status === Event::STATUS_REVISION_REQUESTED
                        && $scope->ppa_status === EventParticipationScope::PPA_REVISION_REQUESTED
                        && $scope->event?->organization_id
                        && $adminScope->organizationId === $scope->event->organization_id;
                }

                return $this->adminScopeOwnsParticipantScope($adminScope, $scope);
            })
            ->filter(fn (EventParticipationScope $scope): bool => $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER
                || ! $scope->invitation_acknowledged_at
                || ($scope->ppa_required && in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED], true)))
            ->take($limit)
            ->map(fn (EventParticipationScope $scope): array => $this->scopeActionItem(
                $scope,
                $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER
                    ? 'Proposal revision requested'
                    : ($scope->invitation_acknowledged_at ? 'Participant PPA needs action' : 'Invitation needs acknowledgement'),
                $scope->participation_role === EventParticipationScope::ROLE_ORGANIZER
                    ? route('dashboard.events', ['status' => Event::STATUS_REVISION_REQUESTED])
                    : route('dashboard.events', ['workspace' => 'participating'])
            ))
            ->values();
    }

    public function notifyUser(User $user, string $key, array $payload): bool
    {
        if ($this->hasNotificationKey($user, $key)) {
            return false;
        }

        $this->dispatchedKeys[$user->id.':'.$key] = true;

        $link = $payload['link'] ?? $payload['action_url'] ?? null;
        $message = $payload['message'] ?? $payload['body'] ?? null;
        $linkText = $payload['link_text'] ?? $payload['action_label'] ?? null;

        $notificationPayload = array_merge([
            'notification_key' => $key,
            'created_label' => now()->diffForHumans(),
        ], $payload);

        if ($link !== null) {
            $notificationPayload['link'] = $link;
            $notificationPayload['action_url'] = $link;
        }

        if ($message !== null) {
            $notificationPayload['message'] = $message;
            $notificationPayload['body'] = $message;
        }

        if ($linkText !== null) {
            $notificationPayload['link_text'] = $linkText;
            $notificationPayload['action_label'] = $linkText;
        }

        DB::afterCommit(function () use ($user, $notificationPayload): void {
            $user->notify(new EventWorkflowNotification($notificationPayload));
        });

        return true;
    }

    private function notifyUsers(Collection $users, string $key, string $kind, EventParticipationScope $scope, array $payload): int
    {
        $sent = 0;

        foreach ($users->unique('id') as $user) {
            $userPayload = $this->payload($key, $kind, $scope, $payload);
            if ($this->notifyUser($user, $key, $userPayload)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function payload(string $key, string $kind, EventParticipationScope $scope, array $payload): array
    {
        $scope->loadMissing(['event', 'organization']);

        return array_merge([
            'notification_key' => $key,
            'kind' => $kind,
            'event_id' => $scope->event_id,
            'event_title' => $scope->event?->title,
            'participation_scope_id' => $scope->id,
            'participant_name' => $scope->displayName(),
            'created_label' => now()->diffForHumans(),
        ], $payload);
    }

    private function hasNotificationKey(User $user, string $key): bool
    {
        $memoryKey = $user->id.':'.$key;
        if (isset($this->dispatchedKeys[$memoryKey])) {
            return true;
        }

        return $user->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => ($notification->data['notification_key'] ?? null) === $key);
    }

    private function participantRecipientAdmins(EventParticipationScope $scope): Collection
    {
        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if (! $scope->organization_id && ! $scope->delegated_to_organization_id && ! $scope->college_id) {
            return collect();
        }

        return AdminAssignment::query()
            ->with('user')
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query) use ($scope): void {
                if ($scope->organization_id) {
                    $query->orWhere('organization_id', $scope->organization_id);
                }

                if ($scope->delegated_to_organization_id) {
                    $query->orWhere('organization_id', $scope->delegated_to_organization_id);
                }

                if ($scope->college_id) {
                    $query->orWhere(function ($query) use ($scope): void {
                        $query->where('role_name', 'LSG Admin')
                            ->where('college_id', $scope->college_id);
                    });
                }
            })
            ->get()
            ->pluck('user')
            ->filter();
    }

    private function organizerAndOsaRecipients(EventParticipationScope $scope): Collection
    {
        return $this->organizerRecipients($scope)->merge($this->osaRecipients())->unique('id')->values();
    }

    private function organizerRecipients(EventParticipationScope $scope): Collection
    {
        $scope->loadMissing('event.organization');
        $organizationId = $scope->event?->organization_id;

        if (! $organizationId) {
            return collect();
        }

        return AdminAssignment::query()
            ->with('user')
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('organization_id', $organizationId)
            ->get()
            ->pluck('user')
            ->filter();
    }

    private function osaRecipients(): Collection
    {
        return User::role('Super Admin (OSA)')->get();
    }

    private function adminScopeOwnsParticipantScope($adminScope, EventParticipationScope $scope): bool
    {
        $scope->loadMissing(['organization', 'delegatedOrganization']);

        if ($scope->organization && $adminScope->canAccessOrganization($scope->organization)) {
            return true;
        }

        if ($scope->delegatedOrganization && $adminScope->canAccessOrganization($scope->delegatedOrganization)) {
            return true;
        }

        return $scope->college_id && $adminScope->isLsgAdmin() && $adminScope->collegeId === $scope->college_id;
    }

    private function scopeActionItem(EventParticipationScope $scope, string $label, string $url): array
    {
        return [
            'label' => $label,
            'event_title' => $scope->event?->title,
            'participant_name' => $scope->displayName(),
            'status' => $scope->ppa_status,
            'url' => $url,
        ];
    }

    private function participationScopesForNotification(Event $event): EventParticipationScope
    {
        return app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));
    }
}
