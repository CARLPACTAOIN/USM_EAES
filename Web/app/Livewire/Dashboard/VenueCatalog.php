<?php

namespace App\Livewire\Dashboard;

use App\Models\College;
use App\Models\Venue;
use App\Services\VenueCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VenueCatalog extends Component
{
    #[Locked]
    public ?string $venueId = null;

    public string $search = '';

    public string $status = 'active';

    public string $name = '';

    public string $campusLocation = '';

    public string $collegeId = '';

    public string $managingOffice = '';

    public string $inquiryInstructions = '';

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage-venues'), 403);
    }

    public function edit(string $venueId): void
    {
        $this->authorizeAction();
        $venue = Venue::findOrFail($venueId);
        $this->venueId = $venue->id;
        $this->name = $venue->name;
        $this->campusLocation = $venue->campus_location;
        $this->collegeId = (string) ($venue->college_id ?? '');
        $this->managingOffice = $venue->managing_office;
        $this->inquiryInstructions = (string) ($venue->inquiry_instructions ?? '');
    }

    public function save(VenueCatalogService $catalog): void
    {
        $this->authorizeAction();
        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('venues', 'name')->where(fn ($query) => $query->where('campus_location', trim($this->campusLocation)))->ignore($this->venueId),
            ],
            'campusLocation' => ['required', 'string', 'max:255'],
            'collegeId' => ['nullable', 'uuid', 'exists:colleges,id'],
            'managingOffice' => ['required', 'string', 'max:255'],
            'inquiryInstructions' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'campusLocation' => 'campus location',
            'collegeId' => 'owning college',
            'managingOffice' => 'managing office',
            'inquiryInstructions' => 'inquiry instructions',
        ]);

        $data = [
            'name' => trim($validated['name']),
            'campus_location' => trim($validated['campusLocation']),
            'college_id' => filled($validated['collegeId']) ? $validated['collegeId'] : null,
            'managing_office' => trim($validated['managingOffice']),
            'inquiry_instructions' => filled($validated['inquiryInstructions']) ? trim($validated['inquiryInstructions']) : null,
        ];

        if ($this->venueId) {
            $catalog->update(Venue::findOrFail($this->venueId), auth()->user(), $data);
            $this->notice = 'Venue updated.';
        } else {
            $catalog->create(auth()->user(), $data);
            $this->notice = 'Venue created.';
        }

        $this->resetForm();
    }

    public function retire(string $venueId, VenueCatalogService $catalog): void
    {
        $this->authorizeAction();
        $catalog->retire(Venue::findOrFail($venueId), auth()->user());
        $this->notice = 'Venue retired. Historical permits remain unchanged.';
    }

    public function reactivate(string $venueId, VenueCatalogService $catalog): void
    {
        $this->authorizeAction();
        $catalog->reactivate(Venue::findOrFail($venueId), auth()->user());
        $this->notice = 'Venue reactivated.';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function render(): View
    {
        $venues = Venue::query()
            ->with('college')
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term): void {
                    $query->whereLike('name', $term)
                        ->orWhereLike('campus_location', $term)
                        ->orWhereLike('managing_office', $term);
                });
            })
            ->orderBy('name')
            ->get();

        return view('livewire.dashboard.venue-catalog', [
            'venues' => $venues,
            'colleges' => College::orderBy('name')->get(),
        ]);
    }

    private function authorizeAction(): void
    {
        abort_unless(auth()->user()?->can('manage-venues'), 403);
    }

    private function resetForm(): void
    {
        $this->reset(['venueId', 'name', 'campusLocation', 'collegeId', 'managingOffice', 'inquiryInstructions']);
        $this->resetErrorBag();
    }
}
