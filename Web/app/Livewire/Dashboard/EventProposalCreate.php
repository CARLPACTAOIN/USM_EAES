<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\User;
use App\Services\AdminScopeService;
use App\Services\EventProposalDraftService;
use App\Support\AdminScope;
use App\Support\EvaluationQuestions;
use App\Support\EventEvaluationForm;
use Carbon\Carbon;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Throwable;

class EventProposalCreate extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?string $eventId = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public ?string $currentProposalDocumentName = null;

    public ?string $organizerId = null;

    public string $title = '';

    public string $proposalCategory = '';

    public string $parentEventId = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $locationType = 'on-campus';

    public string $locationDetails = '';

    public string $implementingOffice = '';

    public string $collaboratingOffice = '';

    public string $targetParticipants = '';

    public string $sourceOfFund = '';

    public string $budgetCost = '';

    public string $resolutionNumber = '';

    public $proposalDocument = null;

    public bool $hardcopySubmitted = false;

    public bool $headOrganizationSigned = false;

    public bool $adviserSigned = false;

    public bool $sameTimeForAllDays = true;

    public array $eventDays = [];

    public array $evaluationQuestions = [];

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(?string $eventId = null): void
    {
        abort_unless(auth()->user()?->can('create-proposals'), 403);

        $this->eventId = $eventId;
        $this->editing = filled($eventId);

        if ($this->editing) {
            $event = Event::with(['eventDays', 'evaluationFormVersion', 'organization'])->findOrFail($eventId);
            $this->authorizeEditableEvent($event);
            $this->hydrateFromEvent($event);
            $this->currentProposalDocumentName = $event->proposal_document_original_name;

            return;
        }

        if ($this->hydrateOldInput()) {
            return;
        }

        $this->eventDays = [$this->blankEventDay()];
        $this->evaluationQuestions = $this->defaultEvaluationQuestions();
    }

    public function updatedStartDate(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if ($this->endDate === '' || $this->dateIsBefore($this->endDate, $value)) {
            $this->endDate = $value;
        }

        $this->syncEventDaysToDateRange();
    }

    public function updatedEndDate(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if ($this->startDate === '') {
            $this->startDate = $value;
        }

        if (! $this->dateIsBefore($value, $this->startDate)) {
            $this->syncEventDaysToDateRange();
        }
    }

    public function updatedSameTimeForAllDays(bool $value): void
    {
        if ($value) {
            $this->applyFirstDayTimesToAllDays();
        }
    }

    public function addDay(): void
    {
        if ($this->endDate !== '') {
            try {
                $this->endDate = Carbon::parse($this->endDate)->addDay()->toDateString();
                $this->syncEventDaysToDateRange();

                return;
            } catch (Throwable) {
                // Fall through to the manual blank row.
            }
        }

        $this->eventDays[] = $this->blankEventDay();
    }

    public function removeDay(int $index): void
    {
        if (count($this->eventDays) <= 1) {
            return;
        }

        unset($this->eventDays[$index]);
        $this->eventDays = array_values($this->eventDays);
    }

    public function updated(string $property, mixed $value = null): void
    {
        if (! $this->sameTimeForAllDays || ! str_starts_with($property, 'eventDays.')) {
            return;
        }

        $parts = explode('.', $property);
        $field = $parts[2] ?? null;

        if (! in_array($field, ['start_time', 'end_time'], true)) {
            return;
        }

        $value ??= data_get($this->eventDays, implode('.', array_slice($parts, 1)));

        foreach ($this->eventDays as $index => $day) {
            $this->eventDays[$index][$field] = (string) $value;
        }
    }

    public function applyFirstDayTimesToAllDays(): void
    {
        $firstDay = $this->eventDays[0] ?? [];
        $startTime = (string) ($firstDay['start_time'] ?? '');
        $endTime = (string) ($firstDay['end_time'] ?? '');

        foreach ($this->eventDays as $index => $day) {
            if ($startTime !== '') {
                $this->eventDays[$index]['start_time'] = $startTime;
            }

            if ($endTime !== '') {
                $this->eventDays[$index]['end_time'] = $endTime;
            }
        }
    }

    public function addQuestion(): void
    {
        if (count($this->evaluationQuestions) >= 10) {
            return;
        }

        $this->evaluationQuestions[] = [
            'id' => '',
            'label' => '',
            'description' => '',
        ];
    }

    public function removeQuestion(int $index): void
    {
        if (count($this->evaluationQuestions) <= 1) {
            return;
        }

        unset($this->evaluationQuestions[$index]);
        $this->evaluationQuestions = array_values($this->evaluationQuestions);
    }

    public function createProposal(EventProposalDraftService $proposalDrafts): void
    {
        $this->resetFeedback();
        $user = auth()->user();
        abort_unless($user?->can('create-proposals'), 403);

        if ($this->editing) {
            $this->updateProposal($proposalDrafts);

            return;
        }

        $validated = $this->validatedPayload();
        if ($validated === null) {
            return;
        }

        $isHrEvent = Organization::whereKey($validated['organization_id'])->value('type') === 'hr_unit';
        if (! $isHrEvent && ! $this->proposalDocument) {
            $this->addError('proposalDocument', 'Attach the official PPA proposal softcopy before saving this event.');

            return;
        }

        try {
            $proposalDrafts->create($user, $validated, $this->proposalDocument);
        } catch (DomainException $exception) {
            $this->errorNotice = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            $this->errorNotice = 'Failed to create event: '.$exception->getMessage();

            return;
        }

        session()->flash('success', $isHrEvent
            ? 'HR attendance event created and scanner access is ready.'
            : 'Event proposal created as draft with the official proposal softcopy attached.');

        $this->redirectRoute('dashboard.events', navigate: true);
    }

    private function updateProposal(EventProposalDraftService $proposalDrafts): void
    {
        $event = Event::with(['eventDays', 'organization'])->findOrFail($this->eventId);
        $this->authorizeEditableEvent($event);

        $validated = $this->validatedPayload();
        if ($validated === null) {
            return;
        }

        $organization = $event->organization;
        if (! $organization) {
            $this->errorNotice = 'The proposal organizer could not be resolved.';

            return;
        }

        $isHrEvent = $organization->type === 'hr_unit';
        if (! $isHrEvent && ! $event->proposal_document_path && ! $this->proposalDocument) {
            $this->addError('proposalDocument', 'Attach the official PPA proposal softcopy before saving this event.');

            return;
        }

        try {
            $event = $proposalDrafts->update($event, auth()->user(), $validated, $this->proposalDocument);
        } catch (DomainException $exception) {
            $this->errorNotice = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            $this->errorNotice = 'Failed to update event: '.$exception->getMessage();

            return;
        }

        session()->flash('success', 'Event proposal updated.');

        $this->redirectRoute('dashboard.events', ['status' => $event->status], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.dashboard.event-proposal-create', [
            'parentEvents' => $this->parentEvents(),
            'organizerOptions' => $this->organizerOptions(),
            'isOsaCreator' => ! $this->editing && (bool) auth()->user()?->hasRole('Super Admin (OSA)'),
            'editing' => $this->editing,
            'currentProposalDocumentName' => $this->currentProposalDocumentName,
        ]);
    }

    private function validatedPayload(): ?array
    {
        $this->validate([
            'organizerId' => [auth()->user()?->hasRole('Super Admin (OSA)') ? 'required' : 'nullable', 'uuid', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'proposalCategory' => ['required', 'string', 'in:program,project,activity'],
            'parentEventId' => ['nullable', 'uuid', 'exists:events,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'locationType' => ['required', 'string', 'in:on-campus,off-campus'],
            'locationDetails' => ['nullable', 'string'],
            'implementingOffice' => ['nullable', 'string', 'max:255'],
            'collaboratingOffice' => ['nullable', 'string', 'max:255'],
            'targetParticipants' => ['nullable', 'string', 'max:1000'],
            'sourceOfFund' => ['nullable', 'string', 'max:255'],
            'budgetCost' => ['nullable', 'numeric', 'min:0'],
            'resolutionNumber' => ['nullable', 'string', 'max:100'],
            'proposalDocument' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'hardcopySubmitted' => ['boolean'],
            'headOrganizationSigned' => ['boolean'],
            'adviserSigned' => ['boolean'],
            'sameTimeForAllDays' => ['boolean'],
            'eventDays' => ['required', 'array', 'min:1'],
            'eventDays.*.date' => ['required', 'date'],
            'eventDays.*.start_time' => ['required', 'date_format:H:i'],
            'eventDays.*.end_time' => ['required', 'date_format:H:i'],
            'evaluationQuestions' => ['required', 'array', 'min:1', 'max:10'],
            'evaluationQuestions.*.id' => ['nullable', 'string', 'max:80'],
            'evaluationQuestions.*.label' => ['required', 'string', 'max:180'],
            'evaluationQuestions.*.description' => ['nullable', 'string', 'max:500'],
        ], [], $this->validationAttributes());

        if (! $this->validateEventDays()) {
            return null;
        }

        $scope = $this->adminScope();
        $organizationId = $scope?->isSuperAdmin()
            ? $this->organizerId
            : $scope?->organizationId;

        if (! $organizationId) {
            abort(403, 'Your account must be assigned to an organization before creating proposals.');
        }

        return [
            'organization_id' => $organizationId,
            'parent_event_id' => $this->parentEventId !== '' ? $this->parentEventId : null,
            'title' => $this->title,
            'proposal_category' => $this->proposalCategory,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'location_type' => $this->locationType,
            'location_details' => $this->blankToNull($this->locationDetails),
            'target_demographics' => [
                'implementing_office' => $this->blankToNull($this->implementingOffice),
                'collaborating_office' => $this->blankToNull($this->collaboratingOffice),
                'target_participants' => $this->blankToNull($this->targetParticipants),
            ],
            'budget_allocations' => [
                'source_of_fund' => $this->blankToNull($this->sourceOfFund),
                'budget_cost' => $this->budgetCost !== '' ? $this->budgetCost : null,
            ],
            'resolution_number' => $this->blankToNull($this->resolutionNumber),
            'hardcopy_submitted' => $this->hardcopySubmitted,
            'head_organization_signed' => $this->headOrganizationSigned,
            'adviser_signed' => $this->adviserSigned,
            'event_days' => $this->eventDays,
            'evaluation_questions' => $this->evaluationQuestions,
        ];
    }

    private function validateEventDays(): bool
    {
        try {
            $startDate = Carbon::parse($this->startDate)->startOfDay();
            $endDate = Carbon::parse($this->endDate)->startOfDay();
        } catch (Throwable) {
            return false;
        }

        $valid = true;
        foreach ($this->eventDays as $index => $dayData) {
            try {
                $dayDate = Carbon::parse($dayData['date'] ?? '')->startOfDay();
            } catch (Throwable) {
                continue;
            }

            if ($dayDate->lt($startDate) || $dayDate->gt($endDate)) {
                $this->addError("eventDays.{$index}.date", sprintf('Day %d date must be within the event start and end dates.', $index + 1));
                $valid = false;
            }

            if (($dayData['start_time'] ?? '') !== '' && ($dayData['end_time'] ?? '') !== '' && $dayData['end_time'] <= $dayData['start_time']) {
                $this->addError("eventDays.{$index}.end_time", sprintf('Day %d end time must be later than the start time.', $index + 1));
                $valid = false;
            }
        }

        return $valid;
    }

    private function parentEvents(): Collection
    {
        return Event::query()
            ->with('organization')
            ->when($this->eventId, fn ($query) => $query->whereKeyNot($this->eventId))
            ->whereHas('organization', fn ($query) => $query->whereIn('type', ['usg', 'lsg', 'academic_unit', 'administrative_office']))
            ->whereIn('status', [
                Event::STATUS_DRAFT,
                Event::STATUS_SUBMITTED,
                Event::STATUS_UNDER_REVIEW,
                Event::STATUS_REVISION_REQUESTED,
                Event::STATUS_APPROVED,
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    private function hydrateFromEvent(Event $event): void
    {
        $target = $event->target_demographics ?? [];
        $budget = $event->budget_allocations ?? [];

        $this->organizerId = (string) $event->organization_id;
        $this->title = (string) $event->title;
        $this->proposalCategory = (string) $event->proposal_category;
        $this->parentEventId = (string) ($event->parent_event_id ?? '');
        $this->startDate = $event->start_date?->toDateString() ?? '';
        $this->endDate = $event->end_date?->toDateString() ?? '';
        $this->locationType = (string) ($event->location_type ?: 'on-campus');
        $this->locationDetails = (string) ($event->location_details ?? '');
        $this->implementingOffice = (string) ($target['implementing_office'] ?? '');
        $this->collaboratingOffice = (string) ($target['collaborating_office'] ?? '');
        $this->targetParticipants = (string) ($target['target_participants'] ?? '');
        $this->sourceOfFund = (string) ($budget['source_of_fund'] ?? '');
        $this->budgetCost = isset($budget['budget_cost']) && $budget['budget_cost'] !== null
            ? (string) $budget['budget_cost']
            : '';
        $this->resolutionNumber = (string) ($event->resolution_number ?? '');
        $this->hardcopySubmitted = (bool) $event->hardcopy_submitted;
        $this->headOrganizationSigned = (bool) $event->head_organization_signed;
        $this->adviserSigned = (bool) $event->adviser_signed;
        $this->sameTimeForAllDays = true;
        $this->eventDays = $event->eventDays
            ->sortBy('day_number')
            ->map(fn (EventDay $day): array => [
                'date' => $day->date?->toDateString() ?? '',
                'start_time' => $day->start_time ? substr((string) $day->start_time, 0, 5) : '',
                'end_time' => $day->end_time ? substr((string) $day->end_time, 0, 5) : '',
            ])
            ->values()
            ->all();

        if ($this->eventDays === []) {
            $this->eventDays = [$this->blankEventDay()];
            $this->syncEventDaysToDateRange();
        }

        $this->evaluationQuestions = collect(EventEvaluationForm::questionsForEvent($event))
            ->map(fn (array $question): array => [
                'id' => $question['id'] ?? '',
                'label' => $question['label'] ?? '',
                'description' => $question['description'] ?? '',
            ])
            ->values()
            ->all();
    }

    private function authorizeEditableEvent(Event $event): void
    {
        $user = auth()->user();
        abort_unless($user?->can('create-proposals'), 403);
        abort_if($user->hasRole('Super Admin (OSA)'), 403, 'OSA reviews proposals but does not edit organizer submissions.');

        $scope = $this->adminScope();
        abort_unless($scope?->organizationId === $event->organization_id, 403, 'Only the event organizer can edit this proposal.');

        abort_unless(in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REVISION_REQUESTED], true), 403, 'Only draft or revision-requested proposals can be edited.');
    }

    private function organizerOptions(): Collection
    {
        if (! auth()->user()?->hasRole('Super Admin (OSA)')) {
            return collect();
        }

        return Organization::query()
            ->whereIn('type', ['society', 'usg', 'lsg', 'academic_unit', 'administrative_office', 'non_academic_org', 'hr_unit'])
            ->orderBy('name')
            ->get();
    }

    private function adminScope(): ?AdminScope
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(AdminScopeService::class)->forUser($user)
            : null;
    }

    private function validationAttributes(): array
    {
        return [
            'organizerId' => 'organizer',
            'proposalCategory' => 'PPA category',
            'parentEventId' => 'parent permit',
            'startDate' => 'start date',
            'endDate' => 'end date',
            'locationType' => 'location type',
            'locationDetails' => 'location details',
            'implementingOffice' => 'implementing office',
            'collaboratingOffice' => 'collaborating office',
            'targetParticipants' => 'target participants',
            'sourceOfFund' => 'source of fund',
            'budgetCost' => 'budget cost',
            'resolutionNumber' => 'resolution number',
            'proposalDocument' => 'official PPA proposal softcopy',
            'hardcopySubmitted' => 'hardcopy submitted',
            'headOrganizationSigned' => 'head signed',
            'sameTimeForAllDays' => 'same time for all days',
            'eventDays' => 'event days',
            'eventDays.*.date' => 'event day date',
            'eventDays.*.start_time' => 'event day start time',
            'eventDays.*.end_time' => 'event day end time',
            'evaluationQuestions' => 'evaluation questions',
            'evaluationQuestions.*.label' => 'evaluation question',
            'evaluationQuestions.*.description' => 'evaluation question description',
        ];
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }

    private function hydrateOldInput(): bool
    {
        $old = session()->getOldInput();

        if ($old === []) {
            return false;
        }

        $this->organizerId = (string) data_get($old, 'organization_id', '');
        $this->title = (string) data_get($old, 'title', '');
        $this->proposalCategory = (string) data_get($old, 'proposal_category', '');
        $this->parentEventId = (string) data_get($old, 'parent_event_id', '');
        $this->startDate = (string) data_get($old, 'start_date', '');
        $this->endDate = (string) data_get($old, 'end_date', '');
        $this->locationType = (string) data_get($old, 'location_type', 'on-campus');
        $this->locationDetails = (string) data_get($old, 'location_details', '');
        $this->implementingOffice = (string) data_get($old, 'implementing_office', '');
        $this->collaboratingOffice = (string) data_get($old, 'collaborating_office', '');
        $this->targetParticipants = (string) data_get($old, 'target_participants', '');
        $this->sourceOfFund = (string) data_get($old, 'source_of_fund', '');
        $this->budgetCost = (string) data_get($old, 'budget_cost', '');
        $this->resolutionNumber = (string) data_get($old, 'resolution_number', '');
        $this->hardcopySubmitted = $this->oldBoolean($old, 'hardcopy_submitted');
        $this->headOrganizationSigned = $this->oldBoolean($old, 'head_organization_signed');
        $this->adviserSigned = $this->oldBoolean($old, 'adviser_signed');
        $this->sameTimeForAllDays = $this->oldBoolean($old, 'same_time_for_all_days', true);
        $this->eventDays = $this->normalizeEventDays(data_get($old, 'event_days'));
        $this->evaluationQuestions = $this->normalizeEvaluationQuestions(data_get($old, 'evaluation_questions'));

        if ($this->eventDays === []) {
            $this->eventDays = [$this->blankEventDay()];
            $this->syncEventDaysToDateRange();
        }

        if ($this->evaluationQuestions === []) {
            $this->evaluationQuestions = $this->defaultEvaluationQuestions();
        }

        return true;
    }

    private function oldBoolean(array $old, string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $old)) {
            return $default;
        }

        return in_array((string) $old[$key], ['1', 'true', 'on', 'yes'], true);
    }

    private function syncEventDaysToDateRange(): void
    {
        try {
            $startDate = Carbon::parse($this->startDate)->startOfDay();
            $endDate = Carbon::parse($this->endDate)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($endDate->lt($startDate)) {
            return;
        }

        $existingByDate = collect($this->eventDays)
            ->filter(fn (array $day): bool => filled($day['date'] ?? null))
            ->keyBy('date');
        $existingByIndex = array_values($this->eventDays);
        $days = [];
        $cursor = $startDate->copy();
        $index = 0;

        while ($cursor->lte($endDate)) {
            $date = $cursor->toDateString();
            $existing = $existingByDate->get($date, $existingByIndex[$index] ?? []);

            $days[] = [
                'date' => $date,
                'start_time' => (string) ($existing['start_time'] ?? ''),
                'end_time' => (string) ($existing['end_time'] ?? ''),
            ];

            $cursor->addDay();
            $index++;
        }

        $this->eventDays = $days !== [] ? $days : [$this->blankEventDay()];

        if ($this->sameTimeForAllDays) {
            $this->applyFirstDayTimesToAllDays();
        }
    }

    private function normalizeEventDays(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        return collect($days)
            ->map(fn (mixed $day): array => [
                'date' => (string) data_get($day, 'date', ''),
                'start_time' => (string) data_get($day, 'start_time', ''),
                'end_time' => (string) data_get($day, 'end_time', ''),
            ])
            ->filter(fn (array $day): bool => filled($day['date']) || filled($day['start_time']) || filled($day['end_time']))
            ->values()
            ->all();
    }

    private function normalizeEvaluationQuestions(mixed $questions): array
    {
        if (! is_array($questions)) {
            return [];
        }

        return collect($questions)
            ->map(fn (mixed $question): array => [
                'id' => (string) data_get($question, 'id', ''),
                'label' => (string) data_get($question, 'label', ''),
                'description' => (string) data_get($question, 'description', ''),
            ])
            ->filter(fn (array $question): bool => filled($question['id']) || filled($question['label']) || filled($question['description']))
            ->values()
            ->all();
    }

    private function defaultEvaluationQuestions(): array
    {
        return collect(EvaluationQuestions::defaultSchema())
            ->map(fn (array $question): array => [
                'id' => $question['id'],
                'label' => $question['label'],
                'description' => $question['description'],
            ])
            ->values()
            ->all();
    }

    private function blankEventDay(): array
    {
        return [
            'date' => '',
            'start_time' => '',
            'end_time' => '',
        ];
    }

    private function dateIsBefore(string $date, string $comparison): bool
    {
        try {
            return Carbon::parse($date)->startOfDay()->lt(Carbon::parse($comparison)->startOfDay());
        } catch (Throwable) {
            return false;
        }
    }
}
