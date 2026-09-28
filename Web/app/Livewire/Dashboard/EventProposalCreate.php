<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminScopeService;
use App\Services\EventProposalDraftService;
use App\Services\EventSessionScheduleService;
use App\Services\VenueAvailabilityService;
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

    #[Locked]
    public ?string $currentVenueConfirmationDocumentName = null;

    public ?string $organizerId = null;

    public string $title = '';

    public string $proposalCategory = '';

    public string $activityRole = 'organizer';

    public string $parentEventId = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $locationType = 'on-campus';

    public string $locationDetails = '';

    public string $venueId = '';

    public string $venueConfirmationOffice = '';

    public string $venueConfirmationDate = '';

    public string $venueConfirmationReferenceNumber = '';

    public $venueConfirmationDocument = null;

    public string $implementingOffice = '';

    public string $collaboratingOffice = '';

    public string $targetParticipants = '';

    public string $sourceOfFund = '';

    public string $budgetCost = '';

    public string $resolutionNumber = '';

    public string $proponentName = '';

    public string $proponentPosition = '';

    public string $proponentOrganization = '';

    public string $proponentEmail = '';

    public string $proponentContactNumber = '';

    public string $rationale = '';

    public string $generalObjective = '';

    public string $specificObjectives = '';

    public string $methodology = '';

    public string $majorActivities = '';

    public string $expectedOutput = '';

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
            $event = Event::with(['canonicalPpaPacket', 'eventDays.sessions', 'evaluationFormVersion', 'organization'])->findOrFail($eventId);
            $this->authorizeEditableEvent($event);
            $this->hydrateFromEvent($event);
            $this->currentProposalDocumentName = $event->proposal_document_original_name;
            $this->currentVenueConfirmationDocumentName = $event->venue_confirmation_document_original_name;

            return;
        }

        if ($this->hydrateOldInput()) {
            return;
        }

        $this->eventDays = [$this->blankEventDay()];
        $this->evaluationQuestions = $this->defaultEvaluationQuestions();
        $this->hydrateProponentDefaults();
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

    public function updatedLocationType(string $value): void
    {
        if ($value !== 'on-campus') {
            $this->venueId = '';
        }

        if ($value !== 'off-campus') {
            $this->venueConfirmationOffice = '';
            $this->venueConfirmationDate = '';
            $this->venueConfirmationReferenceNumber = '';
            $this->venueConfirmationDocument = null;
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

        try {
            $proposalDrafts->create($user, $validated, $this->proposalDocument, $this->venueConfirmationDocument);
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

        try {
            $event = $proposalDrafts->update($event, auth()->user(), $validated, $this->proposalDocument, $this->venueConfirmationDocument);
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
            'currentVenueConfirmationDocumentName' => $this->currentVenueConfirmationDocumentName,
            'activeVenues' => Venue::active()->with('college')->orderBy('name')->get(),
            'readinessItems' => $this->readinessItems(),
            'venueAvailabilityPreview' => $this->venueAvailabilityPreview(),
        ]);
    }

    private function validatedPayload(): ?array
    {
        $this->validate([
            'organizerId' => [auth()->user()?->hasRole('Super Admin (OSA)') ? 'required' : 'nullable', 'uuid', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'proposalCategory' => ['required', 'string', 'in:program,project,activity'],
            'activityRole' => ['required', 'string', 'in:organizer,co-organizer'],
            'parentEventId' => ['nullable', 'uuid', 'exists:events,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'locationType' => ['required', 'string', 'in:on-campus,off-campus,online'],
            'locationDetails' => ['nullable', 'string'],
            'venueId' => ['nullable', 'uuid', 'exists:venues,id'],
            'venueConfirmationOffice' => ['nullable', 'string', 'max:255'],
            'venueConfirmationDate' => ['nullable', 'date', 'before_or_equal:today'],
            'venueConfirmationReferenceNumber' => ['nullable', 'string', 'max:255'],
            'venueConfirmationDocument' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
            'implementingOffice' => ['nullable', 'string', 'max:255'],
            'collaboratingOffice' => ['nullable', 'string', 'max:255'],
            'targetParticipants' => ['nullable', 'string', 'max:1000'],
            'sourceOfFund' => ['nullable', 'string', 'max:255'],
            'budgetCost' => ['nullable', 'numeric', 'min:0'],
            'resolutionNumber' => ['nullable', 'string', 'max:100'],
            'proponentName' => ['nullable', 'string', 'max:255'],
            'proponentPosition' => ['nullable', 'string', 'max:255'],
            'proponentOrganization' => ['nullable', 'string', 'max:255'],
            'proponentEmail' => ['nullable', 'email', 'max:255'],
            'proponentContactNumber' => ['nullable', 'string', 'max:50'],
            'rationale' => ['nullable', 'string', 'max:5000'],
            'generalObjective' => ['nullable', 'string', 'max:2000'],
            'specificObjectives' => ['nullable', 'string', 'max:5000'],
            'methodology' => ['nullable', 'string', 'max:5000'],
            'majorActivities' => ['nullable', 'string', 'max:5000'],
            'expectedOutput' => ['nullable', 'string', 'max:5000'],
            'proposalDocument' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'sameTimeForAllDays' => ['boolean'],
            'eventDays' => ['required', 'array', 'min:1'],
            'eventDays.*.date' => ['required', 'date'],
            'eventDays.*.start_time' => ['required', 'date_format:H:i'],
            'eventDays.*.end_time' => ['required', 'date_format:H:i'],
            'eventDays.*.sessions' => ['nullable', 'array', 'min:1'],
            'eventDays.*.sessions.*.name' => ['nullable', 'string', 'max:80'],
            'eventDays.*.sessions.*.start_time' => ['required', 'date_format:H:i'],
            'eventDays.*.sessions.*.end_time' => ['required', 'date_format:H:i'],
            'eventDays.*.sessions.*.required_for_attendance' => ['boolean'],
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
            'canonical_packet' => [
                'activity_role' => $this->activityRole,
                'proponent_name' => $this->blankToNull($this->proponentName),
                'proponent_position' => $this->blankToNull($this->proponentPosition),
                'proponent_organization' => $this->blankToNull($this->proponentOrganization),
                'proponent_email' => $this->blankToNull($this->proponentEmail),
                'proponent_contact_number' => $this->blankToNull($this->proponentContactNumber),
                'rationale' => $this->blankToNull($this->rationale),
                'general_objective' => $this->blankToNull($this->generalObjective),
                'specific_objectives' => $this->specificObjectives,
                'methodology' => $this->blankToNull($this->methodology),
                'major_activities' => $this->majorActivities,
                'expected_output' => $this->blankToNull($this->expectedOutput),
            ],
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'location_type' => $this->locationType,
            'location_details' => $this->blankToNull($this->locationDetails),
            'venue_id' => $this->blankToNull($this->venueId),
            'venue_confirmation_office' => $this->blankToNull($this->venueConfirmationOffice),
            'venue_confirmation_date' => $this->blankToNull($this->venueConfirmationDate),
            'venue_confirmation_reference_number' => $this->blankToNull($this->venueConfirmationReferenceNumber),
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

        try {
            app(EventSessionScheduleService::class)->normalizeEventDays($this->eventDays);
        } catch (DomainException $exception) {
            $this->addError('eventDays', $exception->getMessage());
            $valid = false;
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
        $packet = $event->canonicalPpaPacket;

        $this->organizerId = (string) $event->organization_id;
        $this->title = (string) $event->title;
        $this->proposalCategory = (string) $event->proposal_category;
        $this->activityRole = in_array((string) $packet?->activity_role, ['organizer', 'co-organizer'], true) ? (string) $packet->activity_role : 'organizer';
        $this->parentEventId = (string) ($event->parent_event_id ?? '');
        $this->startDate = $event->start_date?->toDateString() ?? '';
        $this->endDate = $event->end_date?->toDateString() ?? '';
        $this->locationType = (string) ($event->location_type ?: 'on-campus');
        $this->locationDetails = (string) ($event->location_details ?? '');
        $this->venueId = (string) ($event->venue_id ?? '');
        $this->venueConfirmationOffice = (string) ($event->venue_confirmation_office ?? '');
        $this->venueConfirmationDate = $event->venue_confirmation_date?->toDateString() ?? '';
        $this->venueConfirmationReferenceNumber = (string) ($event->venue_confirmation_reference_number ?? '');
        $this->implementingOffice = (string) ($target['implementing_office'] ?? '');
        $this->collaboratingOffice = (string) ($target['collaborating_office'] ?? '');
        $this->targetParticipants = (string) ($target['target_participants'] ?? '');
        $this->sourceOfFund = (string) ($budget['source_of_fund'] ?? '');
        $this->budgetCost = isset($budget['budget_cost']) && $budget['budget_cost'] !== null
            ? (string) $budget['budget_cost']
            : '';
        $this->resolutionNumber = (string) ($event->resolution_number ?? '');
        $this->proponentName = (string) ($packet?->proponent_name ?? '');
        $this->proponentPosition = (string) ($packet?->proponent_position ?? '');
        $this->proponentOrganization = (string) ($packet?->proponent_organization ?? '');
        $this->proponentEmail = (string) ($packet?->proponent_email ?? '');
        $this->proponentContactNumber = (string) ($packet?->proponent_contact_number ?? '');
        $this->rationale = (string) ($packet?->rationale ?? '');
        $this->generalObjective = (string) ($packet?->general_objective ?? '');
        $this->specificObjectives = implode(PHP_EOL, $packet?->specific_objectives ?? []);
        $this->methodology = (string) ($packet?->methodology ?? '');
        $this->majorActivities = implode(PHP_EOL, $packet?->major_activities ?? []);
        $this->expectedOutput = (string) ($packet?->expected_output ?? '');
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
                'sessions' => $day->sessions
                    ->sortBy('session_number')
                    ->map(fn ($session): array => [
                        'name' => (string) $session->name,
                        'start_time' => $session->start_time ? substr((string) $session->start_time, 0, 5) : '',
                        'end_time' => $session->end_time ? substr((string) $session->end_time, 0, 5) : '',
                        'required_for_attendance' => (bool) $session->required_for_attendance,
                    ])
                    ->values()
                    ->all(),
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

    private function hydrateProponentDefaults(): void
    {
        $user = auth()->user();
        $scope = $this->adminScope();

        $this->proponentName = (string) ($user?->name ?? '');
        $this->proponentEmail = (string) ($user?->email ?? '');

        if ($scope?->organizationId) {
            $organization = Organization::find($scope->organizationId);
            $this->proponentOrganization = (string) ($organization?->name ?? '');
        }
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
            'activityRole' => 'activity role',
            'parentEventId' => 'parent permit',
            'startDate' => 'start date',
            'endDate' => 'end date',
            'locationType' => 'location type',
            'locationDetails' => 'location details',
            'venueId' => 'on-campus venue',
            'venueConfirmationOffice' => 'confirming office',
            'venueConfirmationDate' => 'confirmation date',
            'venueConfirmationReferenceNumber' => 'confirmation reference number',
            'venueConfirmationDocument' => 'venue confirmation evidence',
            'implementingOffice' => 'implementing office',
            'collaboratingOffice' => 'collaborating office',
            'targetParticipants' => 'target participants',
            'sourceOfFund' => 'source of fund',
            'budgetCost' => 'budget cost',
            'resolutionNumber' => 'resolution number',
            'proposalDocument' => 'official PPA proposal softcopy',
            'proponentName' => 'proponent name',
            'proponentPosition' => 'proponent position',
            'proponentOrganization' => 'proponent organization',
            'proponentEmail' => 'proponent email',
            'proponentContactNumber' => 'proponent contact number',
            'generalObjective' => 'general objective',
            'specificObjectives' => 'specific objectives',
            'majorActivities' => 'major activities',
            'expectedOutput' => 'expected output',
            'hardcopySubmitted' => 'hardcopy submitted',
            'headOrganizationSigned' => 'head signed',
            'sameTimeForAllDays' => 'same time for all days',
            'eventDays' => 'event days',
            'eventDays.*.date' => 'event day date',
            'eventDays.*.start_time' => 'event day start time',
            'eventDays.*.end_time' => 'event day end time',
            'eventDays.*.sessions.*.name' => 'session name',
            'eventDays.*.sessions.*.start_time' => 'session start time',
            'eventDays.*.sessions.*.end_time' => 'session end time',
            'eventDays.*.sessions.*.required_for_attendance' => 'session required flag',
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
        $oldRole = (string) data_get($old, 'activity_role', 'organizer');
        $this->activityRole = in_array($oldRole, ['organizer', 'co-organizer'], true) ? $oldRole : 'organizer';
        $this->parentEventId = (string) data_get($old, 'parent_event_id', '');
        $this->startDate = (string) data_get($old, 'start_date', '');
        $this->endDate = (string) data_get($old, 'end_date', '');
        $this->locationType = (string) data_get($old, 'location_type', 'on-campus');
        $this->locationDetails = (string) data_get($old, 'location_details', '');
        $this->venueId = (string) data_get($old, 'venue_id', '');
        $this->venueConfirmationOffice = (string) data_get($old, 'venue_confirmation_office', '');
        $this->venueConfirmationDate = (string) data_get($old, 'venue_confirmation_date', '');
        $this->venueConfirmationReferenceNumber = (string) data_get($old, 'venue_confirmation_reference_number', '');
        $this->implementingOffice = (string) data_get($old, 'implementing_office', '');
        $this->collaboratingOffice = (string) data_get($old, 'collaborating_office', '');
        $this->targetParticipants = (string) data_get($old, 'target_participants', '');
        $this->sourceOfFund = (string) data_get($old, 'source_of_fund', '');
        $this->budgetCost = (string) data_get($old, 'budget_cost', '');
        $this->resolutionNumber = (string) data_get($old, 'resolution_number', '');
        $this->proponentName = (string) data_get($old, 'proponent_name', '');
        $this->proponentPosition = (string) data_get($old, 'proponent_position', '');
        $this->proponentOrganization = (string) data_get($old, 'proponent_organization', '');
        $this->proponentEmail = (string) data_get($old, 'proponent_email', '');
        $this->proponentContactNumber = (string) data_get($old, 'proponent_contact_number', '');
        $this->rationale = (string) data_get($old, 'rationale', '');
        $this->generalObjective = (string) data_get($old, 'general_objective', '');
        $this->specificObjectives = (string) data_get($old, 'specific_objectives', '');
        $this->methodology = (string) data_get($old, 'methodology', '');
        $this->majorActivities = (string) data_get($old, 'major_activities', '');
        $this->expectedOutput = (string) data_get($old, 'expected_output', '');
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
                'sessions' => is_array($existing['sessions'] ?? null) ? $existing['sessions'] : [],
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
                'sessions' => $this->normalizeSessions(data_get($day, 'sessions')),
            ])
            ->filter(fn (array $day): bool => filled($day['date']) || filled($day['start_time']) || filled($day['end_time']) || $day['sessions'] !== [])
            ->values()
            ->all();
    }

    private function normalizeSessions(mixed $sessions): array
    {
        if (! is_array($sessions)) {
            return [];
        }

        return collect($sessions)
            ->map(fn (mixed $session): array => [
                'name' => (string) data_get($session, 'name', ''),
                'start_time' => (string) data_get($session, 'start_time', ''),
                'end_time' => (string) data_get($session, 'end_time', ''),
                'required_for_attendance' => filter_var(data_get($session, 'required_for_attendance', true), FILTER_VALIDATE_BOOL),
            ])
            ->filter(fn (array $session): bool => filled($session['name']) || filled($session['start_time']) || filled($session['end_time']))
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
            'sessions' => [],
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

    /** @return array<string, bool> */
    private function readinessItems(): array
    {
        return [
            'Venue selected or described' => $this->locationType === 'on-campus'
                ? filled($this->venueId) || filled($this->locationDetails)
                : filled($this->locationDetails),
            'Off-campus confirming office recorded' => $this->locationType !== 'off-campus' || filled($this->venueConfirmationOffice),
            'Off-campus confirmation date recorded' => $this->locationType !== 'off-campus' || filled($this->venueConfirmationDate),
            'Off-campus evidence attached' => $this->locationType !== 'off-campus' || filled($this->venueConfirmationDocument) || filled($this->currentVenueConfirmationDocumentName),
            'Activity role selected' => filled($this->activityRole),
            'Objectives drafted' => filled($this->generalObjective) || filled($this->specificObjectives),
            'Budget recorded' => filled($this->sourceOfFund) && filled($this->budgetCost),
        ];
    }

    /** @return array{confirmed: list<array<string, mixed>>, tentative: list<array<string, mixed>>, disclaimer: string, availability_url: ?string} */
    private function venueAvailabilityPreview(): array
    {
        if ($this->locationType !== 'on-campus') {
            return ['confirmed' => [], 'tentative' => [], 'disclaimer' => app(VenueAvailabilityService::class)->disclaimer(), 'availability_url' => null];
        }

        $venueName = $this->selectedVenueName();
        $availability = app(VenueAvailabilityService::class)->warningsForVenueSchedule(
            venueName: $venueName,
            eventDays: $this->eventDays,
            exceptEventId: $this->eventId
        );

        $firstDay = collect($this->eventDays)->first(fn (array $day): bool => filled($day['date'] ?? null));
        $availability['availability_url'] = route('dashboard.venue-availability', [
            'date' => $firstDay['date'] ?? $this->startDate,
            'startTime' => $firstDay['start_time'] ?? null,
            'endTime' => $firstDay['end_time'] ?? null,
            'search' => $venueName,
        ]);

        return $availability;
    }

    private function selectedVenueName(): string
    {
        if (filled($this->venueId)) {
            return (string) Venue::whereKey($this->venueId)->value('name');
        }

        return $this->locationType === 'on-campus' ? $this->locationDetails : '';
    }
}
