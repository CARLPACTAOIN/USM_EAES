<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Services\EventAnalyticsService;
use App\Support\EventTenantScope;
use App\Support\YearLevels;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EventAnalyticsParticipantFilters extends Component
{
    use WithPagination;

    #[Locked]
    public string $eventId;

    #[Locked]
    public array $scopeOptions = [];

    #[Locked]
    public array $dayOptions = [];

    #[Url(except: '')]
    public string $tab = 'attendance';

    #[Url(as: 'scope_id', except: '')]
    public string $scopeId = '';

    #[Url(as: 'day_id', except: '')]
    public string $dayId = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'all')]
    public string $validation = 'all';

    #[Url(as: 'year_level', except: '')]
    public string $yearLevel = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public function mount(string $eventId, array $filters = [], array $scopeOptions = [], array $dayOptions = []): void
    {
        $this->eventId = $eventId;
        $this->scopeOptions = $scopeOptions;
        $this->dayOptions = $dayOptions;
        $this->tab = 'attendance';
        $this->scopeId = (string) ($filters['scope_id'] ?? '');
        $this->dayId = (string) ($filters['day_id'] ?? '');
        $this->status = (string) ($filters['status'] ?? 'all');
        $this->validation = (string) ($filters['validation'] ?? 'all');
        $this->yearLevel = (string) ($filters['year_level'] ?? '');
        $this->search = (string) ($filters['q'] ?? '');
        $this->perPage = $this->normalizePerPage((int) ($filters['per_page'] ?? 25));

        if ($this->scopeOptions === [] || $this->dayOptions === []) {
            $this->hydrateOptionsFromAnalytics();
        }
    }

    public function clearFilters(): mixed
    {
        $this->authorizeEventAccess();

        $this->scopeId = '';
        $this->dayId = '';
        $this->status = 'all';
        $this->validation = 'all';
        $this->yearLevel = '';
        $this->search = '';
        $this->perPage = 25;
        $this->tab = 'attendance';
        $this->resetPage();

        return null;
    }

    public function applyFilters(): mixed
    {
        $this->authorizeEventAccess();
        $this->search = trim($this->search);
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->tab = 'attendance';
        $this->resetPage();

        return null;
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->resetPage();
    }

    public function render()
    {
        $event = $this->authorizeEventAccess();
        $participantRows = app(EventAnalyticsService::class)
            ->participantRows($event, $this->serviceFilters(), $this->perPage);

        return view('livewire.dashboard.event-analytics-participant-filters', [
            'activeFilterCount' => $this->activeFilterCount(),
            'filterActionUrl' => route('dashboard.events.analytics', $event),
            'filterResetUrl' => route('dashboard.events.analytics', ['event' => $event, 'tab' => 'attendance']),
            'participantRows' => $participantRows,
            'scopeOptions' => $this->scopeOptions,
            'dayOptions' => $this->dayOptions,
            'statusOptions' => $this->statusOptions(),
            'validationOptions' => $this->validationOptions(),
            'yearLevelOptions' => YearLevels::options(),
        ]);
    }

    private function authorizeEventAccess(): Event
    {
        $event = Event::findOrFail($this->eventId);

        EventTenantScope::authorize($event, auth()->user());

        return $event;
    }

    private function hydrateOptionsFromAnalytics(): void
    {
        $event = $this->authorizeEventAccess();
        $analytics = app(EventAnalyticsService::class)->summary($event);

        $this->scopeOptions = collect($analytics['participant_scopes'] ?? [])
            ->map(fn (array $scope): array => [
                'id' => (string) $scope['id'],
                'name' => (string) $scope['name'],
            ])
            ->values()
            ->all();

        $this->dayOptions = collect($analytics['daily_attendance'] ?? [])
            ->map(fn (array $day): array => [
                'id' => (string) $day['id'],
                'label' => (string) $day['label'],
                'date' => (string) $day['date'],
            ])
            ->values()
            ->all();
    }

    private function filterParams(): array
    {
        return [
            'scope_id' => $this->scopeId,
            'day_id' => $this->dayId,
            'status' => $this->status !== 'all' ? $this->status : null,
            'validation' => $this->validation !== 'all' ? $this->validation : null,
            'year_level' => $this->yearLevel,
            'q' => trim($this->search),
            'per_page' => $this->perPage !== 25 ? (string) $this->normalizePerPage($this->perPage) : null,
        ];
    }

    private function activeFilterCount(): int
    {
        return collect($this->filterParams())
            ->filter(fn ($value): bool => filled($value))
            ->count();
    }

    private function serviceFilters(): array
    {
        return [
            'scope_id' => $this->scopeId,
            'day_id' => $this->dayId,
            'status' => $this->status,
            'validation' => $this->validation,
            'year_level' => $this->yearLevel,
            'q' => trim($this->search),
        ];
    }

    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    }

    private function statusOptions(): array
    {
        return [
            'all' => 'All statuses',
            'attended' => 'Attended',
            'present_on_time' => 'On time',
            'late' => 'Late',
            'late_cutoff' => 'Late cutoff',
            'left_early' => 'Left early',
            'absent' => 'Absent',
        ];
    }

    private function validationOptions(): array
    {
        return [
            'all' => 'All validation',
            'valid' => 'Valid',
            'invalid' => 'Invalid or pending',
            'evaluated' => 'Evaluated',
            'not_evaluated' => 'Missing evaluation',
        ];
    }
}
