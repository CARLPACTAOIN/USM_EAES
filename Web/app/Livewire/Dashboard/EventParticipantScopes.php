<?php

namespace App\Livewire\Dashboard;

use App\Models\College;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\EventNotificationService;
use App\Services\ParticipationScopeService;
use App\Support\AdminScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class EventParticipantScopes extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $eventId;

    #[Locked]
    public bool $isParticipatingWorkspace = false;

    public array $ppaSoftcopies = [];

    public array $reviewRemarks = [];

    public string $audienceMode = 'organization';

    public array $selectedCollegeIds = [];

    public array $selectedOrganizationIds = [];

    public string $collegeQuery = '';

    public string $organizationQuery = '';

    public string $captureMode = 'self_scanning';

    public bool $ppaRequired = true;

    public bool $analyticsRollupEnabled = true;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(string $eventId, bool $isParticipatingWorkspace = false): void
    {
        $this->eventId = $eventId;
        $this->isParticipatingWorkspace = $isParticipatingWorkspace;

        $this->syncReviewRemarks($this->eventForDisplay());
    }

    public function setAudienceMode(string $mode): void
    {
        abort_unless(in_array($mode, ['all', 'college', 'organization'], true), 422);

        $this->audienceMode = $mode;
        $this->resetFeedback();
        $this->resetAudienceErrors();

        if ($mode !== 'college') {
            $this->selectedCollegeIds = [];
        }

        if ($mode !== 'organization') {
            $this->selectedOrganizationIds = [];
        }
    }

    public function addParticipantScopes(ParticipationScopeService $participationScopes): void
    {
        $this->resetFeedback();

        $event = $this->eventForDisplay();
        $this->authorizeAddScopes($event);

        $this->validate($this->addScopeRules(), [], [
            'audienceMode' => 'audience mode',
            'selectedCollegeIds' => 'College audience',
            'selectedCollegeIds.*' => 'College audience',
            'selectedOrganizationIds' => 'organization audience',
            'selectedOrganizationIds.*' => 'organization audience',
            'captureMode' => 'capture mode',
        ]);

        $result = $participationScopes->createParticipantScopes(
            event: $event,
            audienceMode: $this->audienceMode,
            organizationIds: $this->selectedOrganizationIds,
            collegeIds: $this->selectedCollegeIds,
            captureMode: $this->captureMode,
            ppaRequired: $this->ppaRequired,
            analyticsRollupEnabled: $this->analyticsRollupEnabled,
        );

        if ($result['created_count'] > 0) {
            $message = $result['created_count'] === 1
                ? 'Participant scope added.'
                : "{$result['created_count']} participant scopes added.";

            if ($result['skipped_organizer_count'] > 0) {
                $message .= ' Organizer audience was skipped because it is already included.';
            }

            if ($result['skipped_existing_count'] > 0) {
                $message .= " {$result['skipped_existing_count']} duplicate audience selection(s) were skipped.";
            }

            $this->notice = $message;
            $this->selectedCollegeIds = [];
            $this->selectedOrganizationIds = [];
            $this->syncReviewRemarks($event->fresh(['participationScopes.organization', 'participationScopes.college', 'participationScopes.delegatedOrganization']));
            $this->dispatchUpdates();

            return;
        }

        $this->errorNotice = $result['skipped_organizer_count'] > 0 && $result['skipped_existing_count'] === 0
            ? 'The organizer is already included through the organizer attendance scope and does not need a participant PPA.'
            : 'Every selected audience already has an active participant scope for the event.';
    }

    #[On('event-proposal-workflow-updated')]
    #[On('participant-scopes-updated')]
    public function refreshScopes(): void
    {
        $this->syncReviewRemarks($this->eventForDisplay());
    }

    public function acknowledgeInvitation(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();

        $scope = $this->scopeForAction($scopeId);

        if (! $notifications->acknowledgeInvitation($scope, auth()->user())) {
            abort(403, 'Only the invited participant organization can acknowledge this invitation.');
        }

        $this->notice = 'Participant invitation acknowledged.';
        $this->dispatchUpdates();
    }

    public function submitParticipantPpa(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();

        $scope = $this->scopeForAction($scopeId);
        $this->authorizeParticipantScopeAccess($scope);

        if (! $scope->ppa_required) {
            $this->errorNotice = 'This participant scope does not require a PPA.';

            return;
        }

        if (! in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED], true)) {
            $this->errorNotice = 'Only draft or revision-requested participant PPAs can be submitted.';

            return;
        }

        $rules = [
            "ppaSoftcopies.{$scopeId}" => [$scope->ppa_softcopy_path ? 'nullable' : 'required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ];

        $this->validate($rules, [], [
            "ppaSoftcopies.{$scopeId}" => 'PPA softcopy',
        ]);

        $updates = [
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'hardcopy_submitted' => true,
            'org_head_signed' => true,
            'adviser_signed' => true,
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'review_remarks' => null,
        ];

        $uploadedFile = $this->ppaSoftcopies[$scopeId] ?? null;
        if ($uploadedFile) {
            $updates['ppa_softcopy_path'] = $uploadedFile->store('participant-ppa-documents');
            $updates['ppa_softcopy_original_name'] = $uploadedFile->getClientOriginalName();
        }

        $scope->update($updates);
        unset($this->ppaSoftcopies[$scopeId]);

        $notifications->notifyParticipantScopeSubmitted($scope->fresh(['event.organization', 'organization', 'delegatedOrganization']));

        $this->notice = 'Participant PPA submitted for OSA review.';
        $this->dispatchUpdates();
    }

    public function reviewParticipantPpa(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();
        $scope = $this->scopeForAction($scopeId);
        $this->authorizeOsaScopeAction($scope);

        if ($scope->ppa_status !== EventParticipationScope::PPA_SUBMITTED) {
            $this->errorNotice = 'Only submitted participant PPAs can be reviewed.';

            return;
        }

        $scope->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $notifications->notifyParticipantScopeReviewed($scope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_UNDER_REVIEW);

        $this->notice = 'Participant PPA is now under review.';
        $this->dispatchUpdates();
    }

    public function approveParticipantPpa(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();
        $scope = $this->scopeForAction($scopeId);
        $this->authorizeOsaScopeAction($scope);

        if (! in_array($scope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Participant PPA must be submitted or under review before approval.';

            return;
        }

        if ($scope->ppa_required && (! $scope->ppa_softcopy_path || ! $scope->hardcopy_submitted)) {
            $this->errorNotice = 'Participant PPA softcopy and hardcopy submission are required before approval.';

            return;
        }

        $scope->update([
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'approved_at' => now(),
            'review_remarks' => null,
        ]);

        $notifications->notifyParticipantScopeReviewed($scope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_APPROVED);

        $this->notice = 'Participant PPA approved. Scoped scanner access is now available.';
        $this->dispatchUpdates();
    }

    public function rejectParticipantPpa(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();
        $scope = $this->scopeForAction($scopeId);
        $this->authorizeOsaScopeAction($scope);

        $this->validate([
            "reviewRemarks.{$scopeId}" => ['nullable', 'string', 'max:1000'],
        ], [], [
            "reviewRemarks.{$scopeId}" => 'review note',
        ]);

        if (! in_array($scope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Participant PPA must be submitted or under review before rejection.';

            return;
        }

        $scope->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => $this->reviewRemarks[$scopeId] ?? null,
        ]);

        $notifications->notifyParticipantScopeReviewed($scope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_REJECTED);

        $this->notice = 'Participant PPA rejected.';
        $this->dispatchUpdates();
    }

    public function requestParticipantPpaRevision(EventNotificationService $notifications, string $scopeId): void
    {
        $this->resetFeedback();
        $scope = $this->scopeForAction($scopeId);
        $this->authorizeOsaScopeAction($scope);

        $this->validate([
            "reviewRemarks.{$scopeId}" => ['required', 'string', 'max:1000'],
        ], [], [
            "reviewRemarks.{$scopeId}" => 'revision note',
        ]);

        if (! in_array($scope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Participant PPA must be submitted or under review before requesting revision.';

            return;
        }

        $scope->update([
            'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => trim($this->reviewRemarks[$scopeId] ?? ''),
        ]);

        $notifications->notifyParticipantScopeReviewed($scope->fresh(['event.organization', 'organization', 'delegatedOrganization']), EventParticipationScope::PPA_REVISION_REQUESTED);

        $this->notice = 'Participant PPA returned for revision.';
        $this->dispatchUpdates();
    }

    public function render(): View
    {
        $event = $this->eventForDisplay();
        $eventScopes = $this->visibleScopes($event);

        return view('livewire.dashboard.event-participant-scopes', [
            'event' => $event,
            'eventScopes' => $eventScopes,
            'scopeActionState' => $this->scopeActionState($eventScopes),
            'canAddScopes' => $this->canAddScopes($event),
            'collegeOptions' => $this->collegeOptions(),
            'organizationOptions' => $this->organizationOptions(),
            'selectionCount' => $this->selectionCount(),
            'user' => auth()->user(),
        ]);
    }

    private function eventForDisplay(): Event
    {
        $event = Event::with([
            'organization',
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.delegatedOrganization',
        ])->findOrFail($this->eventId);

        $this->authorizeDisplayAccess($event);

        return $event;
    }

    private function scopeForAction(string $scopeId): EventParticipationScope
    {
        $event = $this->eventForDisplay();
        $scope = EventParticipationScope::with(['event.organization', 'organization', 'delegatedOrganization'])
            ->findOrFail($scopeId);

        abort_unless($scope->event_id === $event->id, 404);

        return $scope;
    }

    private function visibleScopes(Event $event): Collection
    {
        $scopes = $event->participationScopes
            ->sortBy([['participation_role', 'asc'], ['participant_name_snapshot', 'asc']])
            ->values();

        if (! $this->isParticipatingWorkspace) {
            return $scopes;
        }

        $participationScopes = app(ParticipationScopeService::class);

        return $scopes
            ->filter(fn (EventParticipationScope $scope): bool => $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT
                && $participationScopes->canManageScope(auth()->user(), $scope))
            ->values();
    }

    private function scopeActionState(Collection $eventScopes): array
    {
        $user = auth()->user();
        $isOsa = (bool) $user?->hasRole('Super Admin (OSA)');
        $participationScopes = app(ParticipationScopeService::class);

        return $eventScopes
            ->mapWithKeys(function (EventParticipationScope $scope) use ($user, $isOsa, $participationScopes): array {
                $ownsScope = (bool) ($user && $participationScopes->canManageScope($user, $scope));
                $isParticipant = $scope->participation_role === EventParticipationScope::ROLE_PARTICIPANT;

                return [
                    $scope->id => [
                        'ownsScope' => $ownsScope,
                        'canAcknowledge' => $isParticipant && $ownsScope && ! $isOsa && ! $scope->invitation_acknowledged_at,
                        'canSubmit' => $isParticipant
                            && $scope->ppa_required
                            && $ownsScope
                            && ! $isOsa
                            && in_array($scope->ppa_status, [EventParticipationScope::PPA_DRAFT, EventParticipationScope::PPA_REVISION_REQUESTED], true),
                        'canReview' => $isParticipant && $isOsa && $scope->ppa_status === EventParticipationScope::PPA_SUBMITTED,
                        'canFinalize' => $isParticipant
                            && $isOsa
                            && in_array($scope->ppa_status, [EventParticipationScope::PPA_SUBMITTED, EventParticipationScope::PPA_UNDER_REVIEW], true),
                    ],
                ];
            })
            ->all();
    }

    private function authorizeDisplayAccess(Event $event): void
    {
        $user = auth()->user();
        $adminScope = $this->adminScope();

        abort_unless($user && $adminScope, 403);

        if ($adminScope->isSuperAdmin()) {
            abort_if($event->status === 'draft', 403, 'Draft proposals are visible only to their organizers until submitted.');

            return;
        }

        if ($adminScope->canAccessEvent($event)) {
            return;
        }

        $canManageParticipantScope = $event->participationScopes
            ->contains(fn (EventParticipationScope $scope): bool => app(ParticipationScopeService::class)->canManageScope($user, $scope));

        abort_unless($canManageParticipantScope, 403, $adminScope->isLsgAdmin()
            ? 'Access denied. LSG Admin restricted to college boundary.'
            : 'Access denied. Restricted to your organization boundary.');
    }

    private function authorizeParticipantScopeAccess(EventParticipationScope $scope): void
    {
        abort_unless(app(ParticipationScopeService::class)->canManageScope(auth()->user(), $scope), 403, 'Access denied. Restricted to the participant scope owner or event organizer.');
    }

    private function authorizeAddScopes(Event $event): void
    {
        abort_unless($this->canAddScopes($event), 403);
    }

    private function authorizeOsaScopeAction(EventParticipationScope $scope): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin (OSA)'), 403);
        abort_unless($scope->event_id === $this->eventId, 404);
    }

    private function canAddScopes(Event $event): bool
    {
        $user = auth()->user();
        $adminScope = $this->adminScope();

        return (bool) ($user
            && ! $this->isParticipatingWorkspace
            && ! $user->hasRole('Super Admin (OSA)')
            && $user->can('create-proposals')
            && $adminScope?->canAccessEvent($event));
    }

    private function addScopeRules(): array
    {
        $rules = [
            'audienceMode' => ['required', 'string', 'in:all,college,organization'],
            'captureMode' => ['required', 'string', 'in:self_scanning,parent_scanning,delegated_scanning,central_gate'],
            'ppaRequired' => ['boolean'],
            'analyticsRollupEnabled' => ['boolean'],
        ];

        if ($this->audienceMode === 'organization') {
            $rules['selectedOrganizationIds'] = ['required', 'array', 'min:1'];
            $rules['selectedOrganizationIds.*'] = ['uuid', 'exists:organizations,id'];
        }

        if ($this->audienceMode === 'college') {
            $rules['selectedCollegeIds'] = ['required', 'array', 'min:1'];
            $rules['selectedCollegeIds.*'] = ['uuid', 'exists:colleges,id'];
        }

        return $rules;
    }

    private function collegeOptions(): Collection
    {
        return College::query()
            ->orderBy('name')
            ->get()
            ->values();
    }

    private function organizationOptions(): Collection
    {
        return Organization::query()
            ->with('college')
            ->whereIn('type', ['society', 'usg', 'lsg', 'academic_unit', 'administrative_office', 'non_academic_org', 'hr_unit'])
            ->orderBy('name')
            ->get()
            ->values();
    }

    private function selectionCount(): int
    {
        return match ($this->audienceMode) {
            'all' => College::count(),
            'college' => count($this->selectedCollegeIds),
            default => count($this->selectedOrganizationIds),
        };
    }

    private function syncReviewRemarks(Event $event): void
    {
        foreach ($this->visibleScopes($event) as $scope) {
            $this->reviewRemarks[$scope->id] ??= $scope->review_remarks ?: 'Returned by OSA for revision.';
        }
    }

    private function adminScope(): ?AdminScope
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(AdminScopeService::class)->forUser($user)
            : null;
    }

    private function dispatchUpdates(): void
    {
        $this->dispatch('participant-scopes-updated');
        $this->dispatch('event-proposal-workflow-updated');
        $this->dispatch('notifications-updated');
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }

    private function resetAudienceErrors(): void
    {
        $this->resetErrorBag('selectedCollegeIds');
        $this->resetErrorBag('selectedCollegeIds.*');
        $this->resetErrorBag('selectedOrganizationIds');
        $this->resetErrorBag('selectedOrganizationIds.*');
    }
}
