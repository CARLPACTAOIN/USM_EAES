<?php

namespace App\Livewire\Dashboard;

use App\Services\VenueAvailabilityService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class VenueAvailability extends Component
{
    public string $date = '';

    public string $startTime = '';

    public string $endTime = '';

    public string $search = '';

    public string $status = 'all';

    public string $source = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('create-proposals'), 403);

        $this->date = (string) request('date', today()->toDateString());
        $this->startTime = (string) request('startTime', '');
        $this->endTime = (string) request('endTime', '');
        $this->search = (string) request('search', '');
    }

    public function clearFilters(): void
    {
        $this->reset(['startTime', 'endTime', 'search']);
        $this->status = 'all';
        $this->source = 'all';
    }

    public function render(VenueAvailabilityService $availability): View
    {
        return view('livewire.dashboard.venue-availability', [
            'availability' => $availability->availabilityFor(
                date: $this->date,
                startTime: $this->startTime,
                endTime: $this->endTime,
                search: $this->search,
                status: $this->status,
                source: $this->source,
            ),
        ]);
    }
}
