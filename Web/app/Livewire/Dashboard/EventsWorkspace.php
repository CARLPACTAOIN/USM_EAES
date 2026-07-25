<?php

namespace App\Livewire\Dashboard;

use App\Services\DashboardEventWorkspaceService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EventsWorkspace extends Component
{
    use WithPagination;

    #[Url(except: 'organized')]
    public string $workspace = 'organized';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'per_page', except: 15)]
    public int $perPage = 15;

    public function mount(?string $workspace = null, ?string $status = null, ?string $search = null, int $perPage = 15): void
    {
        $this->workspace = $workspace === 'participating' ? 'participating' : 'organized';
        $this->status = (string) $status;
        $this->search = trim((string) $search);
        $this->perPage = $this->normalizePerPage($perPage);

        if ($user = auth()->user()) {
            app(DashboardEventWorkspaceService::class)->refreshLifecycleForDashboard($user);
        }
    }

    public function setWorkspace(string $workspace): void
    {
        $this->workspace = $workspace === 'participating' ? 'participating' : 'organized';
        $this->resetPage();
    }

    public function setStatus(?string $status = null): void
    {
        $this->status = (string) $status;
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->search = trim($this->search);
            $this->resetPage();
        }

        if ($property === 'perPage') {
            $this->perPage = $this->normalizePerPage($this->perPage);
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->status = '';
        $this->search = '';
        $this->perPage = 15;
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function searchTyped(?string $value = null): void
    {
        $this->search = trim((string) $value);
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->search = trim($this->search);
        $this->perPage = $this->normalizePerPage($this->perPage);
        $this->resetPage();
    }

    public function render(DashboardEventWorkspaceService $workspaceService)
    {
        $user = auth()->user();
        $counts = $workspaceService->countsFor($user);
        $events = $workspaceService->eventsFor(
            user: $user,
            workspace: $this->workspace,
            status: $this->status !== '' ? $this->status : null,
            search: $this->search,
            perPage: $this->perPage
        );

        return view('livewire.dashboard.events-workspace', [
            'events' => $events,
            'user' => $user,
            'canCreateProposals' => $user->can('create-proposals'),
            'workspace' => $this->workspace,
            'currentAdminScope' => $workspaceService->currentScopeFor($user),
            'organizedEventsCount' => $counts['organized'],
            'participatingEventsCount' => $counts['participating'],
            'statusFilters' => $workspaceService->statusFiltersFor($user),
            'activeFilterCount' => collect([$this->status, $this->search])->filter(fn ($value) => filled($value))->count(),
        ]);
    }

    private function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, [10, 15, 25, 50], true) ? $perPage : 15;
    }
}
