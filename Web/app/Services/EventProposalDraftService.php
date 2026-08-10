<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\User;
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

    public function __construct(private readonly ParticipationScopeService $participationScopes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?UploadedFile $proposalDocument): Event
    {
        $organization = Organization::find($data['organization_id']);

        if (! $organization || ! in_array($organization->type, self::RECOGNIZED_ORGANIZER_TYPES, true)) {
            throw new DomainException('Select an active recognized organizer before creating a proposal.');
        }

        $this->validateParentEvent($data['parent_event_id']);

        $isHrEvent = $organization->type === 'hr_unit';
        if (! $isHrEvent && ! $proposalDocument) {
            throw new DomainException('Attach the official PPA proposal softcopy before saving this event.');
        }

        $documentPath = $proposalDocument?->store('proposal-documents');

        try {
            return DB::transaction(function () use ($actor, $data, $documentPath, $isHrEvent, $proposalDocument): Event {
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
                    'location_details' => $data['location_details'],
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

                $this->replaceEventDays($event, $data['event_days']);

                EventEvaluationForm::createForEvent(
                    $event,
                    $actor,
                    $data['evaluation_questions'],
                    'Evaluation - '.$event->title
                );

                $this->participationScopes->ensureOrganizerScope($event->fresh(['organization']));

                return $event->fresh(['eventDays', 'evaluationFormVersion', 'organization']);
            });
        } catch (Throwable $exception) {
            if ($documentPath) {
                Storage::delete($documentPath);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, User $actor, array $data, ?UploadedFile $proposalDocument): Event
    {
        $event->loadMissing('organization');

        if (! $event->organization) {
            throw new DomainException('The proposal organizer could not be resolved.');
        }

        $this->validateParentEvent($data['parent_event_id'], $event->id);

        $isHrEvent = $event->organization->type === 'hr_unit';
        if (! $isHrEvent && ! $event->proposal_document_path && ! $proposalDocument) {
            throw new DomainException('Attach the official PPA proposal softcopy before saving this event.');
        }

        $newDocumentPath = $proposalDocument?->store('proposal-documents');
        $oldDocumentPath = $event->proposal_document_path;

        try {
            $updatedEvent = DB::transaction(function () use ($actor, $data, $event, $isHrEvent, $newDocumentPath, $proposalDocument): Event {
                $hardcopySubmitted = $isHrEvent ? false : $data['hardcopy_submitted'];
                $documentPath = $newDocumentPath ?: $event->proposal_document_path;
                $documentOriginalName = $proposalDocument
                    ? $proposalDocument->getClientOriginalName()
                    : $event->proposal_document_original_name;

                $event->update([
                    'parent_event_id' => $data['parent_event_id'],
                    'title' => $data['title'],
                    'proposal_category' => $data['proposal_category'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'location_type' => $data['location_type'],
                    'location_details' => $data['location_details'],
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

                $this->replaceEventDays($event, $data['event_days']);

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

                return $event->fresh(['eventDays', 'evaluationFormVersion', 'organization']);
            });
        } catch (Throwable $exception) {
            if ($newDocumentPath) {
                Storage::delete($newDocumentPath);
            }

            throw $exception;
        }

        if ($newDocumentPath && $oldDocumentPath && $oldDocumentPath !== $newDocumentPath) {
            Storage::delete($oldDocumentPath);
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
     * @param  list<array{date: string, start_time: string, end_time: string}>  $eventDays
     */
    private function replaceEventDays(Event $event, array $eventDays): void
    {
        $event->eventDays()->delete();

        foreach ($eventDays as $index => $dayData) {
            EventDay::create([
                'event_id' => $event->id,
                'day_number' => $index + 1,
                'date' => $dayData['date'],
                'start_time' => $dayData['start_time'],
                'end_time' => $dayData['end_time'],
            ]);
        }
    }
}
