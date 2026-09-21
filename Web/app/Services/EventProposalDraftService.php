<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventPermitVersion;
use App\Models\Organization;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\EventWorkflowNotification;
use App\Support\EventEvaluationForm;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EventProposalDraftService
{
    private const RECOGNIZED_ORGANIZER_TYPES = [
        'society',
        'usg',
        'lsg',
        'academic_unit',
        'administrative_office',
        'non_academic_org',
        'hr_unit',
    ];

    private const PARENT_ORGANIZER_TYPES = [
        'usg',
        'lsg',
        'academic_unit',
        'administrative_office',
    ];

    public function __construct(
        private readonly ParticipationScopeService $participationScopes,
        private readonly EventSessionScheduleService $sessionSchedules,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?UploadedFile $proposalDocument, ?UploadedFile $confirmationDocument = null): Event
    {
        $organization = Organization::find($data['organization_id']);

        if (! $organization || ! in_array($organization->type, self::RECOGNIZED_ORGANIZER_TYPES, true)) {
            throw new DomainException('Select an active recognized organizer before creating a proposal.');
        }

        $this->validateParentEvent($data['parent_event_id']);

        $isHrEvent = $organization->type === 'hr_unit';
        $venueData = $this->venueData($data, $isHrEvent);
        $documentPath = $proposalDocument?->store('proposal-documents');
        $confirmationPath = $confirmationDocument?->store('venue-confirmations');

        try {
            return DB::transaction(function () use ($actor, $confirmationDocument, $confirmationPath, $data, $documentPath, $isHrEvent, $proposalDocument, $venueData): Event {
                $hardcopySubmitted = $isHrEvent ? false : $data['hardcopy_submitted'];

                $event = Event::create([
                    'organization_id' => $data['organization_id'],
                    'parent_event_id' => $data['parent_event_id'],
                    'title' => $data['title'],
                    'event_domain' => $isHrEvent ? 'hr' : 'student_affairs',
                    'proposal_category' => $data['proposal_category'],
                    'status' => $isHrEvent ? Event::STATUS_APPROVED : Event::STATUS_DRAFT,
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'location_type' => $data['location_type'],
                    ...$venueData,
                    'venue_confirmation_office' => $isHrEvent ? null : ($data['venue_confirmation_office'] ?? null),
                    'venue_confirmation_date' => $isHrEvent ? null : ($data['venue_confirmation_date'] ?? null),
                    'venue_confirmation_reference_number' => $isHrEvent ? null : ($data['venue_confirmation_reference_number'] ?? null),
                    'venue_confirmation_document_path' => $isHrEvent ? null : $confirmationPath,
                    'venue_confirmation_document_original_name' => $isHrEvent ? null : $confirmationDocument?->getClientOriginalName(),
                    'target_demographics' => $data['target_demographics'],
                    'budget_allocations' => $data['budget_allocations'],
                    'proposal_document_path' => $documentPath,
                    'proposal_document_original_name' => $proposalDocument?->getClientOriginalName(),
                    'resolution_number' => $data['resolution_number'],
                    'hardcopy_submitted' => $hardcopySubmitted,
                    'hardcopy_submitted_at' => $hardcopySubmitted ? now() : null,
                    'head_organization_signed' => $isHrEvent ? false : $data['head_organization_signed'],
                    'adviser_signed' => $isHrEvent ? false : $data['adviser_signed'],
                ]);

                $this->replaceEventDays($event, $this->sessionSchedules->normalizeEventDays($data['event_days']));

                EventEvaluationForm::createForEvent(
                    $event,
                    $actor,
                    $data['evaluation_questions'],
                    'Evaluation - '.$event->title
                );

                $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']));

                if ($confirmationPath) {
                    $this->auditEvidence($event, $actor, 'venue-confirmation-evidence-uploaded');
                }

                return $event->fresh(['eventDays', 'evaluationFormVersion', 'organization', 'venue']);
            });
        } catch (Throwable $exception) {
            if ($documentPath) {
                Storage::delete($documentPath);
            }

            if ($confirmationPath) {
                Storage::delete($confirmationPath);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, User $actor, array $data, ?UploadedFile $proposalDocument, ?UploadedFile $confirmationDocument = null): Event
    {
        $event->loadMissing('organization');

        if (! $event->organization) {
            throw new DomainException('The proposal organizer could not be resolved.');
        }

        $this->validateParentEvent($data['parent_event_id'], $event->id);

        $isHrEvent = $event->organization->type === 'hr_unit';
        $venueData = $this->venueData($data, $isHrEvent);
        $newDocumentPath = $proposalDocument?->store('proposal-documents');
        $newConfirmationPath = $confirmationDocument?->store('venue-confirmations');
        $oldDocumentPath = $event->proposal_document_path;
        $oldConfirmationPath = $event->venue_confirmation_document_path;

        try {
            $updatedEvent = DB::transaction(function () use ($actor, $confirmationDocument, $data, $event, $isHrEvent, $newConfirmationPath, $newDocumentPath, $oldConfirmationPath, $proposalDocument, $venueData): Event {
                $hardcopySubmitted = $isHrEvent ? false : $data['hardcopy_submitted'];
                $documentPath = $newDocumentPath ?: $event->proposal_document_path;
                $documentOriginalName = $proposalDocument
                    ? $proposalDocument->getClientOriginalName()
                    : $event->proposal_document_original_name;
                $confirmationPath = $newConfirmationPath ?: $event->venue_confirmation_document_path;
                $confirmationOriginalName = $confirmationDocument
                    ? $confirmationDocument->getClientOriginalName()
                    : $event->venue_confirmation_document_original_name;

                $event->update([
                    'parent_event_id' => $data['parent_event_id'],
                    'title' => $data['title'],
                    'proposal_category' => $data['proposal_category'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'location_type' => $data['location_type'],
                    ...$venueData,
                    'venue_confirmation_office' => $isHrEvent ? null : ($data['venue_confirmation_office'] ?? null),
                    'venue_confirmation_date' => $isHrEvent ? null : ($data['venue_confirmation_date'] ?? null),
                    'venue_confirmation_reference_number' => $isHrEvent ? null : ($data['venue_confirmation_reference_number'] ?? null),
                    'venue_confirmation_document_path' => $isHrEvent ? null : $confirmationPath,
                    'venue_confirmation_document_original_name' => $isHrEvent ? null : $confirmationOriginalName,
                    'target_demographics' => $data['target_demographics'],
                    'budget_allocations' => $data['budget_allocations'],
                    'proposal_document_path' => $documentPath,
                    'proposal_document_original_name' => $documentOriginalName,
                    'resolution_number' => $data['resolution_number'],
                    'hardcopy_submitted' => $hardcopySubmitted,
                    'hardcopy_submitted_at' => $hardcopySubmitted
                        ? ($event->hardcopy_submitted_at ?: now())
                        : null,
                    'head_organization_signed' => $isHrEvent ? false : $data['head_organization_signed'],
                    'adviser_signed' => $isHrEvent ? false : $data['adviser_signed'],
                ]);

                $this->replaceEventDays($event, $this->sessionSchedules->normalizeEventDays($data['event_days']));

                EventEvaluationForm::createForEvent(
                    $event->fresh(),
                    $actor,
                    $data['evaluation_questions'],
                    'Evaluation - '.$event->title
                );

                $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']))->update([
                    'ppa_status' => $this->participationScopes->ppaStatusFromEventStatus($event->status),
                    'ppa_softcopy_path' => $documentPath,
                    'ppa_softcopy_original_name' => $documentOriginalName,
                    'hardcopy_submitted' => $hardcopySubmitted,
                    'org_head_signed' => $isHrEvent ? false : $data['head_organization_signed'],
                    'adviser_signed' => $isHrEvent ? false : $data['adviser_signed'],
                ]);

                if ($newConfirmationPath) {
                    $this->auditEvidence($event, $actor, $oldConfirmationPath ? 'venue-confirmation-evidence-replaced' : 'venue-confirmation-evidence-uploaded');
                }

                return $event->fresh(['eventDays', 'evaluationFormVersion', 'organization', 'venue']);
            });
        } catch (Throwable $exception) {
            if ($newDocumentPath) {
                Storage::delete($newDocumentPath);
            }

            if ($newConfirmationPath) {
                Storage::delete($newConfirmationPath);
            }

            throw $exception;
        }

        if ($newDocumentPath && $oldDocumentPath && $oldDocumentPath !== $newDocumentPath && ! $this->isVersionedFile($oldDocumentPath, 'ppa_document_path')) {
            Storage::delete($oldDocumentPath);
        }

        if ($newConfirmationPath && $oldConfirmationPath && $oldConfirmationPath !== $newConfirmationPath && ! $this->isVersionedFile($oldConfirmationPath, 'confirmation_document_path')) {
            Storage::delete($oldConfirmationPath);
        }

        return $updatedEvent;
    }

    private function validateParentEvent(?string $parentEventId, ?string $eventId = null): void
    {
        if (! $parentEventId) {
            return;
        }

        if ($parentEventId === $eventId) {
            throw new DomainException('A proposal cannot be linked to itself as a parent permit.');
        }

        $parentEvent = Event::with('organization')->find($parentEventId);

        if (! $parentEvent) {
            throw new DomainException('The selected parent permit is invalid.');
        }

        if (! in_array($parentEvent->organization?->type, self::PARENT_ORGANIZER_TYPES, true)) {
            throw new DomainException('Sub-events can only be linked to USG or LSG parent events.');
        }
    }

    /**
     * @param  list<array{date: string, start_time: string, end_time: string, sessions?: list<array<string, mixed>>}>  $eventDays
     */
    private function replaceEventDays(Event $event, array $eventDays): void
    {
        $event->eventDays()->delete();

        foreach ($eventDays as $index => $dayData) {
            $eventDay = EventDay::create([
                'event_id' => $event->id,
                'day_number' => $index + 1,
                'date' => $dayData['date'],
                'start_time' => $dayData['start_time'],
                'end_time' => $dayData['end_time'],
            ]);

            $this->sessionSchedules->replaceSessions($eventDay, $dayData['sessions']);
        }
    }

    /** @param array<string, mixed> $data
     * @return array{venue_id: ?string, location_details: ?string}
     */
    private function venueData(array $data, bool $isHrEvent): array
    {
        if ($isHrEvent) {
            return ['venue_id' => null, 'location_details' => $data['location_details']];
        }

        if ($data['location_type'] === 'off-campus') {
            if (filled($data['venue_id'] ?? null)) {
                throw new DomainException('Off-campus proposals must not reference a campus venue.');
            }

            return ['venue_id' => null, 'location_details' => $data['location_details']];
        }

        if (blank($data['venue_id'] ?? null)) {
            return ['venue_id' => null, 'location_details' => $data['location_details']];
        }

        $venue = Venue::active()->find($data['venue_id']);
        if (! $venue) {
            throw new DomainException('Select an active on-campus venue from the catalog.');
        }

        return ['venue_id' => $venue->id, 'location_details' => $venue->displayName()];
    }

    private function isVersionedFile(string $path, string $column): bool
    {
        return EventPermitVersion::where($column, $path)->exists();
    }

    private function auditEvidence(Event $event, User $actor, string $action): void
    {
        AuditLog::create([
            'target_id' => $event->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => ['event_id' => $event->id],
        ]);

        $actor->notify(new EventWorkflowNotification([
            'notification_key' => $action.':'.$event->id.':'.now()->timestamp,
            'kind' => $action,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'title' => $action === 'venue-confirmation-evidence-replaced' ? 'Venue evidence replaced' : 'Venue evidence uploaded',
            'body' => 'Confirmation evidence was saved for '.$event->title.'.',
            'action_label' => 'Open Event Proposals',
            'action_url' => route('dashboard.events'),
            'severity' => 'info',
            'needs_action' => false,
        ]));
    }
}
