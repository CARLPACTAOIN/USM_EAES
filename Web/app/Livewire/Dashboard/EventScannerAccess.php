<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\ScannerSession;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use App\Services\ScannerSessionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EventScannerAccess extends Component
{
    #[Locked]
    public string $eventId;

    public ?string $scopeId = null;

    public string $scannerQuery = '';

    public array $selectedScannerIds = [];

    public string $label = '';

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(string $eventId): void
    {
        $this->eventId = $eventId;
        $this->initializeDefaultScope();
    }

    public function setScope(?string $scopeId = null): void
    {
        $this->scopeId = filled($scopeId) ? $scopeId : null;
        $this->selectedScannerIds = [];
        $this->scannerQuery = '';
        $this->resetFeedback();
    }

    public function addScanner(string $scannerId): void
    {
        $this->resetFeedback();

        if (! $this->scopeId) {
            $this->addError('scopeId', 'Choose an attendance scope before adding scanners.');

            return;
        }

        $scope = EventParticipationScope::findOrFail($this->scopeId);
        $this->authorizeScannerScopeAccess($scope);

        $scanner = User::findOrFail($scannerId);
        $assignments = app(ScannerAssignmentService::class);

        if (! $assignments->canAssignScanner(auth()->user(), $scanner, $scope)) {
            $this->addError('scannerQuery', 'Select scanners from the selected attendance scope.');

            return;
        }

        if (! in_array($scannerId, $this->selectedScannerIds, true)) {
            $this->selectedScannerIds[] = $scannerId;
        }

        $this->scannerQuery = '';
    }

    public function removeScanner(string $scannerId): void
    {
        $this->selectedScannerIds = array_values(array_filter(
            $this->selectedScannerIds,
            fn (string $id) => $id !== $scannerId
        ));
    }

    public function clearSelected(): void
    {
        $this->selectedScannerIds = [];
        $this->scannerQuery = '';
        $this->resetFeedback();
    }

    public function assignScanners(): void
    {
        $this->resetFeedback();

        $event = $this->event();
        if ($event->status !== 'approved') {
            $this->errorNotice = 'Scanner assignments can only be created for approved events.';

            return;
        }

        abort_unless(auth()->user()?->can('assign-scanners-own'), 403);

        if (! $this->scopeId) {
            $this->addError('scopeId', 'Choose an attendance scope.');

            return;
        }

        $scannerIds = collect($this->selectedScannerIds)
            ->filter()
            ->unique()
            ->values();

        if ($scannerIds->isEmpty()) {
            $this->addError('selectedScannerIds', 'Choose at least one scanner from your authorized organization or college.');

            return;
        }

        $scope = EventParticipationScope::findOrFail($this->scopeId);
        abort_unless($scope->event_id === $event->id, 404);
        $this->authorizeScannerScopeAccess($scope);

        $assignments = app(ScannerAssignmentService::class);
        $scannerUsers = User::whereKey($scannerIds)->get()->keyBy('id');

        foreach ($scannerIds as $scannerId) {
            $scanner = $scannerUsers->get($scannerId);
            if (! $scanner || ! $assignments->canAssignScanner(auth()->user(), $scanner, $scope)) {
                $this->addError('selectedScannerIds', 'Select scanners from the selected attendance scope.');

                return;
            }
        }

        DB::transaction(function () use ($event, $scope, $scannerIds, $scannerUsers, $assignments): void {
            foreach ($scannerIds as $scannerId) {
                $assignments->assign(
                    event: $event,
                    scannerUser: $scannerUsers->get($scannerId),
                    assignedBy: auth()->user(),
                    label: $this->label !== '' ? $this->label : null,
                    maxDevices: 1,
                    participationScope: $scope
                );
            }
        });

        $count = $scannerIds->count();
        $this->notice = $count === 1
            ? 'Scanner assignment created.'
            : "{$count} scanner assignments created.";
        $this->selectedScannerIds = [];
        $this->scannerQuery = '';
        $this->label = '';
    }

    public function revokeAssignment(string $assignmentId): void
    {
        $this->resetFeedback();

        $assignment = EventScannerAssignment::with('participationScope')->findOrFail($assignmentId);
        abort_unless($assignment->event_id === $this->eventId, 404);

        if ($assignment->participationScope) {
            $this->authorizeScannerScopeAccess($assignment->participationScope);
        } else {
            $this->authorizeEventAccess($this->event());
        }

        app(ScannerAssignmentService::class)->revoke(
            $assignment,
            auth()->user(),
            'Scanner assignment revoked from dashboard.'
        );

        $this->notice = 'Scanner assignment revoked.';
    }

    public function revokeSession(string $sessionId): void
    {
        $this->resetFeedback();

        $session = ScannerSession::with('participationScope')->findOrFail($sessionId);
        abort_unless($session->event_id === $this->eventId, 404);

        if ($session->participationScope) {
            $this->authorizeScannerScopeAccess($session->participationScope);
        } else {
            $this->authorizeEventAccess($this->event());
        }

        app(ScannerSessionService::class)->revoke(
            $session,
            auth()->user(),
            'Revoked from dashboard.'
        );

        $this->notice = 'Scanner session revoked.';
    }

    public function render(): View
    {
        $event = $this->eventForDisplay();
        $scannerScopes = $this->scannerScopes($event);

        return view('livewire.dashboard.event-scanner-access', [
            'event' => $event,
            'scannerScopes' => $scannerScopes,
            'eligibleScannerUsers' => $this->eligibleScannerUsers(),
            'selectedScanners' => User::whereKey($this->selectedScannerIds)->orderBy('name')->get(),
            'canAssignScanners' => auth()->user()?->can('assign-scanners-own') ?? false,
        ]);
    }

    private function event(): Event
    {
        return Event::findOrFail($this->eventId);
    }

    private function initializeDefaultScope(): void
    {
        $event = Event::with([
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.delegatedOrganization',
        ])->findOrFail($this->eventId);

        $this->scopeId = $this->scannerScopes($event)->first()?->id;
    }

    private function eventForDisplay(): Event
    {
        return Event::with([
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.delegatedOrganization',
            'scannerAssignments' => function ($query): void {
                $query->with(['participationScope.organization', 'scannerUser.organization', 'assignedBy', 'revokedBy', 'scannerSessions'])
                    ->latest();
            },
            'scannerSessions' => function ($query): void {
                $query->with(['scannerUser', 'participationScope.organization'])
                    ->withCount([
                        'rawScans as pending_scans_count' => fn ($query) => $query->whereNull('processed_at'),
                        'rawScans as synced_scans_count',
                        'rawScans as failed_scans_count' => fn ($query) => $query->whereNotNull('rejection_reason'),
                    ])
                    ->latest();
            },
        ])->findOrFail($this->eventId);
    }

    private function scannerScopes(Event $event): Collection
    {
        $participationScopes = app(ParticipationScopeService::class);

        return $event->participationScopes
            ->filter(fn (EventParticipationScope $scope) => $scope->status === EventParticipationScope::STATUS_ACTIVE
                && ! $participationScopes->runtimeGuard($scope)
                && $participationScopes->canManageScope(auth()->user(), $scope))
            ->values();
    }

    private function eligibleScannerUsers(): Collection
    {
        $search = trim($this->scannerQuery);

        if (! auth()->user()?->can('assign-scanners-own') || ! $this->scopeId || $search === '') {
            return collect();
        }

        $scope = EventParticipationScope::find($this->scopeId);
        if (! $scope || $scope->event_id !== $this->eventId) {
            return collect();
        }

        if (! app(ParticipationScopeService::class)->canManageScope(auth()->user(), $scope)) {
            return collect();
        }

        return app(ScannerAssignmentService::class)->eligibleUsers(
            search: $search,
            limit: 8,
            admin: auth()->user(),
            participationScope: $scope
        )->reject(fn (User $user) => in_array($user->id, $this->selectedScannerIds, true));
    }

    private function authorizeEventAccess(Event $event): void
    {
        abort_unless(auth()->user()?->can('assign-scanners-own'), 403);

        $scope = app(AdminScopeService::class)->forUser(auth()->user());
        if (! $scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            if ($event->status === 'draft') {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        if ($scope->canAccessEvent($event)) {
            return;
        }

        abort(403, $scope->isLsgAdmin()
            ? 'Access denied. LSG Admin restricted to college boundary.'
            : 'Access denied. Restricted to your organization boundary.');
    }

    private function authorizeScannerScopeAccess(EventParticipationScope $scope): void
    {
        abort_unless(auth()->user()?->can('assign-scanners-own'), 403);
        abort_unless($scope->event_id === $this->eventId, 404);

        if (app(ParticipationScopeService::class)->canManageScope(auth()->user(), $scope)) {
            return;
        }

        abort(403, 'Access denied. Restricted to scanner scopes you manage.');
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }
}
