<?php

namespace App\Livewire\Dashboard;

use App\Jobs\ResolveAttendanceJob;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\RawScan;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\EventSessionScheduleService;
use App\Services\ParticipationScopeService;
use App\Support\AdminScope;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EventAttendanceRules extends Component
{
    #[Locked]
    public string $eventId;

    #[Locked]
    public bool $isParticipatingWorkspace = false;

    public string $eventLateCutoffMin = '';

    public array $scopeLateCutoffMins = [];

    public array $sessionRows = [];

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(string $eventId, bool $isParticipatingWorkspace = false): void
    {
        $this->eventId = $eventId;
        $this->isParticipatingWorkspace = $isParticipatingWorkspace;

        $event = $this->eventForDisplay();
        $this->eventLateCutoffMin = $event->event_late_cutoff_min !== null
            ? (string) $event->event_late_cutoff_min
            : '';
        $this->scopeLateCutoffMins = $this->visibleParticipantScopes($event)
            ->mapWithKeys(fn (EventParticipationScope $scope) => [
                $scope->id => $scope->late_cutoff_min !== null ? (string) $scope->late_cutoff_min : '',
            ])
            ->all();
        $this->sessionRows = $this->sessionRowsForEvent($event);
    }

    public function setEventPreset(?int $minutes): void
    {
        $this->eventLateCutoffMin = $minutes !== null ? (string) $minutes : '';
        $this->resetFeedback();
        $this->resetErrorBag('eventLateCutoffMin');
    }

    public function setScopePreset(string $scopeId, ?int $minutes): void
    {
        $this->scopeLateCutoffMins[$scopeId] = $minutes !== null ? (string) $minutes : '';
        $this->resetFeedback();
        $this->resetErrorBag("scopeLateCutoffMins.{$scopeId}");
    }

    public function saveEventCutoff(): void
    {
        $this->resetFeedback();

        $event = $this->eventForDisplay();
        $this->authorizeEventRuleEdit($event);

        if ($this->attendancePolicyLocked($event)) {
            $this->errorNotice = 'Attendance rules are locked after the event starts.';

            return;
        }

        $validated = $this->validateOnlyEventCutoff();

        $event->update([
            'event_late_cutoff_min' => $validated['eventLateCutoffMin'],
        ]);

        $freshCutoff = $event->fresh()->event_late_cutoff_min;
        $this->eventLateCutoffMin = $freshCutoff !== null ? (string) $freshCutoff : '';
        $this->notice = 'Attendance rules updated.';
    }

    public function saveScopeCutoff(string $scopeId): void
    {
        $this->resetFeedback();

        $event = $this->eventForDisplay();
        $scope = EventParticipationScope::findOrFail($scopeId);
        abort_unless($scope->event_id === $event->id, 404);

        $this->authorizeParticipantScopeRuleEdit($event, $scope);

        if ($this->attendancePolicyLocked($event)) {
            $this->errorNotice = 'Attendance rules are locked after the event starts.';

            return;
        }

        if ($event->event_late_cutoff_min !== null) {
            $this->errorNotice = 'This event already has an organizer late cutoff. Participant scope cutoffs are available only when the organizer leaves the event cutoff unset.';

            return;
        }

        $validated = $this->validateOnlyScopeCutoff($scopeId);
        $scope->update([
            'late_cutoff_min' => $validated['late_cutoff_min'],
        ]);

        $studentIds = RawScan::query()
            ->where('event_id', $event->id)
            ->where('event_participation_scope_id', $scope->id)
            ->whereNotNull('student_id')
            ->distinct()
            ->pluck('student_id')
            ->all();

        if ($studentIds) {
            ResolveAttendanceJob::dispatch($event->id, $studentIds, $scope->id);
        }

        $freshCutoff = $scope->fresh()->late_cutoff_min;
        $this->scopeLateCutoffMins[$scope->id] = $freshCutoff !== null ? (string) $freshCutoff : '';
        $this->notice = 'Participant late cutoff updated.';
    }

    public function addSession(string $eventDayId): void
    {
        $event = $this->eventForDisplay();
        $this->authorizeEventRuleEdit($event);

        if ($this->sessionEditingLocked($event)) {
            $this->errorNotice = 'Session schedules are locked after the event starts or after scans are captured.';

            return;
        }

        $day = collect($this->sessionRows)->firstWhere('id', $eventDayId);
        if (! $day) {
            return;
        }

        $index = array_search($eventDayId, array_column($this->sessionRows, 'id'), true);
        $sessionCount = count($this->sessionRows[$index]['sessions']);
        $this->sessionRows[$index]['sessions'][] = [
            'name' => 'Session '.($sessionCount + 1),
            'start_time' => $day['start_time'],
            'end_time' => $day['end_time'],
            'required_for_attendance' => true,
        ];
    }

    public function removeSession(string $eventDayId, int $sessionIndex): void
    {
        $event = $this->eventForDisplay();
        $this->authorizeEventRuleEdit($event);

        if ($this->sessionEditingLocked($event)) {
            $this->errorNotice = 'Session schedules are locked after the event starts or after scans are captured.';

            return;
        }

        $dayIndex = array_search($eventDayId, array_column($this->sessionRows, 'id'), true);
        if ($dayIndex === false || count($this->sessionRows[$dayIndex]['sessions']) <= 1) {
            return;
        }

        unset($this->sessionRows[$dayIndex]['sessions'][$sessionIndex]);
        $this->sessionRows[$dayIndex]['sessions'] = array_values($this->sessionRows[$dayIndex]['sessions']);
    }

    public function saveSessions(EventSessionScheduleService $sessionSchedules): void
    {
        $this->resetFeedback();

        $event = $this->eventForDisplay();
        $this->authorizeEventRuleEdit($event);

        if ($this->sessionEditingLocked($event)) {
            $this->errorNotice = 'Session schedules are locked after the event starts or after scans are captured.';

            return;
        }

        $this->validate([
            'sessionRows' => ['required', 'array', 'min:1'],
            'sessionRows.*.date' => ['required', 'date'],
            'sessionRows.*.start_time' => ['required', 'date_format:H:i'],
            'sessionRows.*.end_time' => ['required', 'date_format:H:i'],
            'sessionRows.*.sessions' => ['required', 'array', 'min:1'],
            'sessionRows.*.sessions.*.name' => ['nullable', 'string', 'max:80'],
            'sessionRows.*.sessions.*.start_time' => ['required', 'date_format:H:i'],
            'sessionRows.*.sessions.*.end_time' => ['required', 'date_format:H:i'],
            'sessionRows.*.sessions.*.required_for_attendance' => ['boolean'],
        ]);

        try {
            $normalized = $sessionSchedules->normalizeEventDays($this->sessionRows);
        } catch (\DomainException $exception) {
            $this->addError('sessionRows', $exception->getMessage());

            return;
        }

        foreach ($event->eventDays()->with('sessions')->get() as $eventDay) {
            $row = collect($normalized)->firstWhere('id', $eventDay->id);
            if ($row) {
                $sessionSchedules->replaceSessions($eventDay, $row['sessions']);
            }
        }

        $this->sessionRows = $this->sessionRowsForEvent($event->fresh(['eventDays.sessions']));
        $this->notice = 'Session schedule updated.';
    }

    public function render(): View
    {
        $event = $this->eventForDisplay();
        $participantScopes = $this->visibleParticipantScopes($event);

        return view('livewire.dashboard.event-attendance-rules', [
            'event' => $event,
            'participantScopes' => $participantScopes,
            'attendancePolicyLocked' => $this->attendancePolicyLocked($event),
            'sessionEditingLocked' => $this->sessionEditingLocked($event),
            'canManageEventRules' => $this->canManageEventRules($event),
            'canEditScopeCutoffs' => $this->editableScopeMap($event, $participantScopes),
            'user' => auth()->user(),
        ]);
    }

    private function eventForDisplay(): Event
    {
        $event = Event::with([
            'eventDays',
            'eventDays.sessions',
            'participationScopes.organization',
            'participationScopes.college',
            'participationScopes.delegatedOrganization',
        ])->findOrFail($this->eventId);

        $this->authorizeDisplayAccess($event);

        return $event;
    }

    private function visibleParticipantScopes(Event $event): Collection
    {
        $scopes = $event->participationScopes
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->sortBy('participant_name_snapshot')
            ->values();

        if (! $this->isParticipatingWorkspace) {
            return $scopes;
        }

        $participationScopes = app(ParticipationScopeService::class);

        return $scopes
            ->filter(fn (EventParticipationScope $scope) => $participationScopes->canManageScope(auth()->user(), $scope))
            ->values();
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
            ->contains(fn (EventParticipationScope $scope) => app(ParticipationScopeService::class)->canManageScope($user, $scope));

        abort_unless($canManageParticipantScope, 403, $adminScope->isLsgAdmin()
            ? 'Access denied. LSG Admin restricted to college boundary.'
            : 'Access denied. Restricted to your organization boundary.');
    }

    private function authorizeEventRuleEdit(Event $event): void
    {
        $user = auth()->user();

        abort_unless($user && ! $user->hasRole('Super Admin (OSA)') && $user->can('create-proposals'), 403);
        abort_unless($this->canManageEventRules($event), 403, 'Only the event organizer can update attendance rules.');
    }

    private function authorizeParticipantScopeRuleEdit(Event $event, EventParticipationScope $scope): void
    {
        $user = auth()->user();

        abort_unless($user && ! $user->hasRole('Super Admin (OSA)'), 403);
        abort_unless($scope->event_id === $event->id, 404);
        abort_unless(app(ParticipationScopeService::class)->canManageScope($user, $scope), 403, 'Access denied. Restricted to the participant scope owner.');
    }

    private function canManageEventRules(Event $event): bool
    {
        $user = auth()->user();
        $adminScope = $this->adminScope();

        return (bool) ($user
            && $user->can('create-proposals')
            && ! $user->hasRole('Super Admin (OSA)')
            && $adminScope?->organizationId
            && $event->organization_id === $adminScope->organizationId);
    }

    private function editableScopeMap(Event $event, Collection $participantScopes): array
    {
        $user = auth()->user();
        $attendancePolicyLocked = $this->attendancePolicyLocked($event);

        return $participantScopes
            ->mapWithKeys(fn (EventParticipationScope $scope) => [
                $scope->id => (bool) ($user
                    && ! $user->hasRole('Super Admin (OSA)')
                    && $event->event_late_cutoff_min === null
                    && ! $attendancePolicyLocked
                    && app(ParticipationScopeService::class)->canManageScope($user, $scope)),
            ])
            ->all();
    }

    private function attendancePolicyLocked(Event $event): bool
    {
        $event->loadMissing('eventDays');
        $timezone = config('services.eaes.event_timezone', 'Asia/Manila');
        $eventStartDate = $event->start_date?->copy()->startOfDay();
        $eventEndDate = $event->end_date?->copy()->startOfDay() ?? $eventStartDate;
        $startsAt = $event->eventDays
            ->filter(function (EventDay $day) use ($eventStartDate, $eventEndDate): bool {
                if (! $eventStartDate || ! $eventEndDate || ! $day->date) {
                    return true;
                }

                $dayDate = $day->date->copy()->startOfDay();

                return $dayDate->betweenIncluded($eventStartDate, $eventEndDate);
            })
            ->map(function (EventDay $day) use ($timezone): ?Carbon {
                if (! $day->date || ! $day->start_time) {
                    return null;
                }

                return Carbon::parse($day->date->toDateString().' '.$day->start_time, $timezone);
            })
            ->filter()
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->first();

        if (! $startsAt && $event->start_date) {
            $firstStartTime = $event->eventDays
                ->sortBy('day_number')
                ->first(fn (EventDay $day) => filled($day->start_time))
                ?->start_time;

            if ($firstStartTime) {
                $startsAt = Carbon::parse($event->start_date->toDateString().' '.$firstStartTime, $timezone);
            }
        }

        if (! $startsAt && $event->start_date) {
            $startsAt = Carbon::parse($event->start_date->toDateString(), $timezone)->startOfDay();
        }

        if ($startsAt) {
            return Carbon::now($timezone)->greaterThanOrEqualTo($startsAt);
        }

        return in_array($event->lifecycle_phase, [
            'active',
            'ended',
            'processing',
            'evaluation_open',
            'evaluation_closed',
            'completed',
        ], true);
    }

    private function sessionEditingLocked(Event $event): bool
    {
        return $this->attendancePolicyLocked($event)
            || RawScan::where('event_id', $event->id)->exists();
    }

    private function sessionRowsForEvent(Event $event): array
    {
        $event->loadMissing('eventDays.sessions');
        $sessionSchedules = app(EventSessionScheduleService::class);

        return $event->eventDays
            ->sortBy('day_number')
            ->map(fn (EventDay $day): array => [
                'id' => $day->id,
                'date' => $day->date?->toDateString() ?? '',
                'start_time' => substr((string) $day->start_time, 0, 5),
                'end_time' => substr((string) $day->end_time, 0, 5),
                'sessions' => $sessionSchedules->sessionsForDay($day)
                    ->map(fn ($session): array => [
                        'name' => (string) $session->name,
                        'start_time' => substr((string) $session->start_time, 0, 5),
                        'end_time' => substr((string) $session->end_time, 0, 5),
                        'required_for_attendance' => (bool) $session->required_for_attendance,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function validateOnlyEventCutoff(): array
    {
        $this->validate([
            'eventLateCutoffMin' => ['nullable', 'integer', 'min:0', 'max:240'],
        ], [], [
            'eventLateCutoffMin' => 'event late cutoff',
        ]);

        return [
            'eventLateCutoffMin' => $this->eventLateCutoffMin !== '' ? (int) $this->eventLateCutoffMin : null,
        ];
    }

    private function validateOnlyScopeCutoff(string $scopeId): array
    {
        $this->validate([
            "scopeLateCutoffMins.{$scopeId}" => ['nullable', 'integer', 'min:0', 'max:240'],
        ], [], [
            "scopeLateCutoffMins.{$scopeId}" => 'participant late cutoff',
        ]);

        $value = $this->scopeLateCutoffMins[$scopeId] ?? null;

        return [
            'late_cutoff_min' => $value !== null && $value !== '' ? (int) $value : null,
        ];
    }

    private function adminScope(): ?AdminScope
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(AdminScopeService::class)->forUser($user)
            : null;
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }
}
